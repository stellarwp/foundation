<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Lock;

use DateTimeImmutable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockContendedException;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockLease;
use StellarWP\Foundation\Lock\LockOperation;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\Catalog_Importer;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\Catalog_Synchronizer;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;

final class LockOperationConsumerTest extends TestCase
{
	public function test_ownership_is_held_during_work_and_released_afterward(): void {
		$lock      = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		$operation = new LockOperation($lock);

		$operation->run(
			name: 'catalog:42:sync',
			ttl: 300,
			operation: function () use ($lock): void {
				$this->assertTrue($lock->isAcquired('catalog:42:sync'));
				$this->assertNull($lock->acquire('catalog:42:sync', 300));
			}
		);
		$this->assertFalse($lock->isAcquired('catalog:42:sync'));
	}

	public function test_a_consumer_can_renew_between_batches_to_keep_ownership(): void {
		$clock     = new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00'));
		$lock      = new InMemoryLock($clock);
		$operation = new LockOperation($lock);
		$importer  = new Catalog_Importer();

		$result = $operation->run(
			name: 'catalog:42:sync',
			ttl: 10,
			operation: function (LockLease $lease) use ($clock, $lock, $importer): array {
				$importer->import(42);
				$clock->advance(8);
				$lease->renew();
				$clock->advance(8);

				$this->assertNull($lock->acquire('catalog:42:sync', 10));
				$importer->import(43);
				$lease->renew();
				$clock->advance(8);
				$this->assertNull($lock->acquire('catalog:42:sync', 10));

				return $importer->imported_site_ids;
			}
		);

		$this->assertSame([42, 43], $result);
		$this->assertNotNull($lock->acquire('catalog:42:sync', 10));
	}

	public function test_a_container_resolved_consumer_uses_the_selected_lock(): void {
		$lock     = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		$importer = new Catalog_Importer();

		$this->container->singleton(Lock::class, $lock);
		$this->container->singleton(Catalog_Importer::class, $importer);

		$consumer = $this->container->get(Catalog_Synchronizer::class);

		$this->assertTrue($consumer->synchronize(42));
		$this->assertSame([42], $importer->imported_site_ids);
	}

	public function test_a_consumer_can_use_an_in_memory_lock_replacement(): void {
		$lock     = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		$importer = new Catalog_Importer();
		$consumer = new Catalog_Synchronizer(new LockOperation($lock), $importer);

		$other_owner = $lock->acquire('catalog:42:sync', 300);

		$this->assertNotNull($other_owner);
		$this->assertFalse($consumer->synchronize(42));
		$this->assertSame([], $importer->imported_site_ids);
	}

	public function test_a_consumer_propagates_nested_contention_after_work_has_started(): void {
		$lock        = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		$operation   = new LockOperation($lock);
		$importer    = new Catalog_Importer();
		$consumer    = new Catalog_Synchronizer($operation, $importer);
		$index_owner = $lock->acquire('catalog:index:sync', 300);

		$this->assertNotNull($index_owner);

		$importer->after_import = static function (int $site_id) use ($operation): void {
			$operation->run('catalog:index:sync', 300, static fn (): int => $site_id);
		};

		try {
			$consumer->synchronize(42);
			$this->fail('Nested contention must not be classified as skipped work.');
		} catch (LockContendedException) {
			$this->assertSame([42], $importer->imported_site_ids);
			$this->assertFalse($lock->isAcquired('catalog:42:sync'));
			$this->assertTrue($lock->isAcquired('catalog:index:sync'));
		} finally {
			$lock->release($index_owner);
		}
	}
}
