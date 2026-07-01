<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Lock;

use DateTimeImmutable;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;

final class DatabaseLockTest extends TestCase
{
	private FakeDatabase $database;

	private MutableClock $clock;

	private DatabaseLock $lock;

	protected function setUp(): void {
		parent::setUp();

		$this->database = new FakeDatabase();
		$this->clock    = new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00'));
		$this->lock     = new DatabaseLock($this->database, 'wp_nexcess_foundation_locks', $this->clock);
	}

	public function test_it_acquires_a_database_lock_when_the_written_owner_matches(): void {
		$this->database->rowResults[] = fn (string $sql, FakeDatabase $database): array => [
			'owner'      => $this->extractOwnerFromInsert($database->executed[0]),
			'expires_at' => '2026-01-01 00:01:00',
		];

		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertSame('queue:sync', $token->name);
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $this->database->executed[0]);
	}

	public function test_it_releases_a_lock_for_the_matching_owner(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 1;

		$this->assertTrue($this->lock->release($token));
		$this->assertStringContainsString('DELETE FROM `wp_nexcess_foundation_locks`', $this->database->executed[0]);
		$this->assertStringContainsString('owner', $this->database->executed[0]);
	}

	public function test_it_refreshes_a_lock_for_the_matching_owner(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 1;

		$refreshed = $this->lock->refresh($token, 120);

		$this->assertInstanceOf(LockToken::class, $refreshed);
		$this->assertSame('2026-01-01 00:02:00', $refreshed->expiresAt->format('Y-m-d H:i:s'));
		$this->assertStringContainsString('UPDATE `wp_nexcess_foundation_locks`', $this->database->executed[0]);
	}

	public function test_it_returns_null_when_refresh_does_not_update_a_row(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->database->executeResults[] = 0;

		$this->assertNull($this->lock->refresh($token, 120));
	}

	public function test_it_checks_whether_a_lock_is_acquired(): void {
		$this->database->rowResults[] = ['name' => 'queue:sync'];

		$this->assertTrue($this->lock->isAcquired('queue:sync'));
		$this->assertStringContainsString("expires_at > '2026-01-01 00:00:00'", $this->database->rowQueries[0]);
	}

	public function test_it_rejects_an_invalid_ttl(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->lock->acquire('queue:sync', 0);
	}

	private function extractOwnerFromInsert(string $sql): string {
		preg_match("/VALUES \\('queue:sync', '([a-f0-9]{32})', /", $sql, $matches);

		return $matches[1] ?? '';
	}
}
