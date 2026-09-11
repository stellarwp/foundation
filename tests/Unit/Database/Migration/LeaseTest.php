<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use DateTimeImmutable;
use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Lease;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseScope;
use StellarWP\Foundation\Tests\TestCase;

final class LeaseTest extends TestCase
{
	public function test_it_renews_and_releases_with_the_latest_token(): void {
		$initial   = $this->token('2026-09-01 10:05:00');
		$refreshed = $this->token('2026-09-01 10:10:00');
		$scope     = new TestDatabaseScope();
		$lock      = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('refresh')
			->with($initial, 300)
			->willReturn($refreshed);
		$lock->expects($this->once())
			->method('release')
			->with($refreshed)
			->willReturn(true);

		$lease = new Lease($lock, $scope, 1, $initial, 300);
		$lease->renew();
		$lease->release();

		$this->assertSame(4, $scope->assertions);
	}

	public function test_it_fails_when_lock_ownership_is_lost_during_renewal(): void {
		$token = $this->token('2026-09-01 10:05:00');
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('refresh')
			->with($token, 300)
			->willReturn(null);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('ownership was lost');

		(new Lease($lock, new TestDatabaseScope(), 1, $token, 300))->renew();
	}

	public function test_it_fails_when_lock_ownership_cannot_be_confirmed_during_release(): void {
		$token = $this->token('2026-09-01 10:05:00');
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willReturn(false);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not confirm ownership');

		(new Lease($lock, new TestDatabaseScope(), 1, $token, 300))->release();
	}

	public function test_it_does_not_touch_the_lock_after_the_database_scope_changes(): void {
		$scope            = new TestDatabaseScope();
		$scope->currentId = 2;
		$lock             = $this->createMock(Lock::class);
		$lock->expects($this->never())->method('refresh');

		$this->expectException(DatabaseContextChanged::class);

		(new Lease($lock, $scope, 1, $this->token('2026-09-01 10:05:00'), 300))->renew();
	}

	private function token(string $expiration): LockToken {
		return new LockToken(
			'nx-foundation-database-migrations',
			'owner',
			new DateTimeImmutable($expiration)
		);
	}
}
