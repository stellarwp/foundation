<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

final class DatabaseLockTest extends DatabaseTestCase
{
	protected function configuration(): array {
		return ['database' => ['locks_table' => $this->suffix . '_locks', 'migrations_table' => $this->suffix . '_history']];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->privateTable($this->suffix . '_locks');
		$this->privateTable($this->suffix . '_history');
	}

	public function test_migrations_do_not_create_application_lock_storage(): void {
		$this->container->get(Migrator::class)->migrate();
		self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->container->get(LockTable::class)->name()]));
		$this->expectException(LockUnavailableException::class);
		$this->container->get(DatabaseLock::class)->acquire('test', 60);
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
		self::assertInstanceOf(LockToken::class, $token);
		self::assertTrue($lock->isAcquired($token->name));
		self::assertNull($lock->acquire($token->name, 60));
		$otherOwner = new LockToken($token->name, 'another-owner', $token->expiresAt);
		self::assertFalse($lock->release($otherOwner));
		self::assertNull($lock->refresh($otherOwner, 60));
		$renewed = $lock->refresh($token, 120);
		self::assertInstanceOf(LockToken::class, $renewed);
		self::assertGreaterThan($token->expiresAt, $renewed->expiresAt);
		self::assertTrue($lock->release($renewed));
		self::assertFalse($lock->isAcquired($token->name));
	}

	public function test_expired_owner_cannot_release_a_replacement_and_names_are_case_sensitive(): void {
		$lock = $this->container->get(DatabaseLock::class);
		$lock->initialize();
		$token = $lock->acquire('resource', 60);
		self::assertInstanceOf(LockToken::class, $token);
		$this->db->executeStatement('UPDATE ' . $this->container->get(LockTable::class)->quotedName() . ' SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND');
		$replacement = $lock->acquire('resource', 60);
		self::assertInstanceOf(LockToken::class, $replacement);
		self::assertNotSame($token->owner, $replacement->owner);
		self::assertFalse($lock->release($token));
		self::assertInstanceOf(LockToken::class, $lock->acquire('Resource', 60));
		self::assertTrue($lock->release($replacement));
	}
}
