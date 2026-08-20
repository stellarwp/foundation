<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Lock;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\TestCase;

final class DatabaseLockTest extends TestCase
{
	private FakeDatabase $database;

	private DatabaseLock $lock;

	protected function setUp(): void {
		parent::setUp();

		$this->database = new FakeDatabase();
		$this->lock     = new DatabaseLock($this->database, 'network_foundation_locks');
	}

	public function test_it_acquires_a_database_lock_when_the_written_owner_matches(): void {
		$this->database->rowResults[] = [
			'expires_at' => '2026-01-01 00:01:00.123456',
		];

		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertSame('queue:sync', $token->name);
		$this->assertSame('2026-01-01 00:01:00.123456', $token->expiresAt->format('Y-m-d H:i:s.u'));
		$this->assertSame('UTC', $token->expiresAt->getTimezone()->getName());
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $this->database->executed[0]);
		$this->assertStringContainsString('TIMESTAMPADD(SECOND, 60, UTC_TIMESTAMP(6))', $this->database->executed[0]);
		$this->assertStringNotContainsString('VALUES(owner)', $this->database->executed[0]);
		$this->assertStringContainsString('owner =', $this->database->rowQueries[0]);
		$this->assertStringContainsString('expires_at > UTC_TIMESTAMP(6)', $this->database->rowQueries[0]);
	}

	public function test_it_returns_null_when_the_acquired_lease_is_not_active_during_readback(): void {
		$this->database->rowResults[] = null;

		$this->assertNull($this->lock->acquire('queue:sync', 60));
		$this->assertStringContainsString('owner =', $this->database->rowQueries[0]);
		$this->assertStringContainsString('expires_at > UTC_TIMESTAMP(6)', $this->database->rowQueries[0]);
	}

	public function test_it_releases_a_lock_for_the_matching_owner(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 1;

		$this->assertTrue($this->lock->release($token));
		$this->assertStringContainsString('DELETE FROM `network_foundation_locks`', $this->database->executed[0]);
		$this->assertStringContainsString('owner', $this->database->executed[0]);
		$this->assertStringContainsString('expires_at > UTC_TIMESTAMP(6)', $this->database->executed[0]);
	}

	public function test_it_refreshes_a_lock_for_the_matching_owner(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 1;
		$this->database->rowResults[]     = ['expires_at' => '2026-01-01 00:02:00.654321'];

		$refreshed = $this->lock->refresh($token, 120);

		$this->assertInstanceOf(LockToken::class, $refreshed);
		$this->assertSame('2026-01-01 00:02:00.654321', $refreshed->expiresAt->format('Y-m-d H:i:s.u'));
		$this->assertStringContainsString('UPDATE `network_foundation_locks`', $this->database->executed[0]);
		$this->assertStringContainsString('TIMESTAMPADD(SECOND, 120, UTC_TIMESTAMP(6))', $this->database->executed[0]);
	}

	public function test_it_returns_null_when_a_refreshed_lease_is_not_active_during_readback(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 0;

		$this->assertNull($this->lock->refresh($token, 120));
	}

	public function test_it_returns_a_token_when_refresh_reports_no_change_but_the_lease_is_active(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 0;
		$this->database->rowResults[]     = ['expires_at' => '2026-01-01 00:02:00.000000'];

		$this->assertInstanceOf(LockToken::class, $this->lock->refresh($token, 120));
	}

	public function test_it_returns_null_when_a_refreshed_lock_can_no_longer_be_read(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 1;
		$this->database->rowResults[]     = null;

		$this->assertNull($this->lock->refresh($token, 120));
	}

	public function test_it_fails_closed_when_the_database_omits_the_lock_expiration(): void {
		$this->database->rowResults[] = [];

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('invalid lock expiration');

		$this->lock->acquire('queue:sync', 60);
	}

	public function test_it_fails_closed_when_the_database_returns_an_invalid_lock_expiration(): void {
		$this->database->rowResults[] = [
			'expires_at' => 'invalid',
		];

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('invalid lock expiration');

		$this->lock->acquire('queue:sync', 60);
	}

	public function test_it_checks_whether_a_lock_is_acquired(): void {
		$this->database->rowResults[] = ['name' => 'queue:sync'];

		$this->assertTrue($this->lock->isAcquired('queue:sync'));
		$this->assertStringContainsString('expires_at > UTC_TIMESTAMP(6)', $this->database->rowQueries[0]);
	}

	public function test_it_rejects_an_invalid_ttl(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->lock->acquire('queue:sync', 0);
	}

	public function test_it_rejects_an_empty_name(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->lock->isAcquired('');
	}

	public function test_it_rejects_names_longer_than_the_database_column(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot exceed 191 bytes');

		$this->lock->acquire(str_repeat('a', 192), 60);
	}

	/**
	 * @dataProvider unavailableOperationProvider
	 */
	#[DataProvider('unavailableOperationProvider')]
	public function test_it_normalizes_database_failures(callable $operation, string $databaseMethod): void {
		$database = $this->mock(Database::class);

		$database->shouldReceive($databaseMethod)->andThrow(new QueryException('Query failed.', 'SELECT 1'));

		try {
			$operation(new DatabaseLock($database, 'wp_nx_foundation_locks'));
			$this->fail('Expected the database failure to be normalized.');
		} catch (LockUnavailableException $exception) {
			$this->assertInstanceOf(QueryException::class, $exception->getPrevious());
		}
	}

	/**
	 * @return array<string, array{callable(DatabaseLock): mixed, string}>
	 */
	public static function unavailableOperationProvider(): array {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		return [
			'acquire'     => [static fn (DatabaseLock $lock): ?LockToken => $lock->acquire('queue:sync', 60), 'execute'],
			'release'     => [static fn (DatabaseLock $lock): bool => $lock->release($token), 'execute'],
			'refresh'     => [static fn (DatabaseLock $lock): ?LockToken => $lock->refresh($token, 60), 'execute'],
			'is acquired' => [static fn (DatabaseLock $lock): bool => $lock->isAcquired('queue:sync'), 'row'],
		];
	}
}
