<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Lock;

use DateTimeImmutable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\InMemoryLock;
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

		$this->assertTrue($operation->run(
			name: 'catalog:42:sync',
			ttl: 300,
			operation: function () use ($lock): void {
				$this->assertSame(0, func_num_args());
				$this->assertTrue($lock->isAcquired('catalog:42:sync'));
				$this->assertNull($lock->acquire('catalog:42:sync', 300));
			}
		));
		$this->assertFalse($lock->isAcquired('catalog:42:sync'));
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
}
