<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\LockDatabase;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockLease;
use StellarWP\Foundation\Lock\LockOperation;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\LockDatabase\DatabaseLock;
use StellarWP\Foundation\LockDatabase\LockDatabaseProvider;
use StellarWP\Foundation\LockDatabase\Tables\LockTable;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

final class DatabaseLockTest extends DatabaseTestCase
{
	protected function configuration(): array {
		return [
			'migrations' => ['table' => $this->suffix . '_history'],
			'lock'       => ['database' => ['table' => $this->suffix . '_locks']],
		];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->container->register(LockDatabaseProvider::class);
		$this->privateTable($this->suffix . '_locks');
		$this->privateTable($this->suffix . '_history');
	}

	public function test_migrations_do_not_create_application_lock_storage(): void {
		$this->container->register(MigrationsProvider::class);
		$this->container->get(Migrator::class)->migrate();
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->container->get(LockTable::class)->name()]));
		$this->expectException(LockUnavailableException::class);
		$this->container->get(DatabaseLock::class)->acquire('test', 60);
	}

	public function test_application_selection_supports_managed_lock_operations(): void {
		$this->container->singleton(Lock::class, static fn (C $c): DatabaseLock => $c->get(DatabaseLock::class));
		$lock = $this->container->get(DatabaseLock::class);
		$lock->initialize();

		$result = $this->container->get(LockOperation::class)->run('import', 60, static function (LockLease $lease) use ($lock): string {
			self::assertTrue($lock->isAcquired('import'));
			self::assertNull($lock->acquire('import', 60));
			$lease->renew();

			return 'imported';
		});

		$this->assertSame('imported', $result);
		$this->assertFalse($lock->isAcquired('import'));
	}

	public function test_a_missing_wordpress_connection_is_reported_through_the_lock_contract(): void {
		$lock = $this->container->get(DatabaseLock::class);
		$lock->initialize();
		$native = $this->native($this->source);
		$this->source->__set('dbh', null);

		try {
			$this->expectException(LockUnavailableException::class);
			$lock->acquire('resource', 60);
		} finally {
			$this->source->__set('dbh', $native);
		}
	}

	public function test_explicit_setup_contention_refresh_and_owner_safe_release(): void {
		$lock = $this->container->get(DatabaseLock::class);
		$lock->initialize();
		$lock->initialize();
		$token = $lock->acquire("A ' quoted resource", 60);
		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertTrue($lock->isAcquired($token->name));
		$this->assertNull($lock->acquire($token->name, 60));
		$otherOwner = new LockToken($token->name, 'another-owner', $token->expiresAt);
		$this->assertFalse($lock->release($otherOwner));
		$this->assertNull($lock->refresh($otherOwner, 60));
		$renewed = $lock->refresh($token, 120);
		$this->assertInstanceOf(LockToken::class, $renewed);
		$this->assertGreaterThan($token->expiresAt, $renewed->expiresAt);
		$this->assertTrue($lock->release($renewed));
		$this->assertFalse($lock->isAcquired($token->name));
	}

	public function test_expired_owner_cannot_release_a_replacement_and_names_are_case_sensitive(): void {
		$lock = $this->container->get(DatabaseLock::class);
		$lock->initialize();
		$token = $lock->acquire('resource', 60);
		$this->assertInstanceOf(LockToken::class, $token);
		$this->db->executeStatement('UPDATE ' . $this->container->get(LockTable::class)->quotedName() . ' SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND');
		$replacement = $lock->acquire('resource', 60);
		$this->assertInstanceOf(LockToken::class, $replacement);
		$this->assertNotSame($token->owner, $replacement->owner);
		$this->assertFalse($lock->release($token));
		$this->assertInstanceOf(LockToken::class, $lock->acquire('Resource', 60));
		$this->assertTrue($lock->release($replacement));
	}
}
