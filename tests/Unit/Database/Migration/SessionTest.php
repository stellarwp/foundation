<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use DateTimeImmutable;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Migration\Lease;
use StellarWP\Foundation\Database\Migration\Session;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FailingMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseScope;
use StellarWP\Foundation\Tests\TestCase;

final class SessionTest extends TestCase
{
	public function test_it_maintains_the_lease_around_an_applied_migration(): void {
		$events    = [];
		$migration = $this->createMock(Migration::class);
		$migration->method('id')->willReturn('2026_09_01_000001_create_reports');
		$migration->expects($this->once())
			->method('up')
			->willReturnCallback(static function (Schema $schema) use (&$events): void {
				$events[] = 'apply';
			});
		$session = new Session(new RecordingSchema(), $this->lease(2, $events));

		$session->apply($migration);

		$this->assertSame(['renew', 'apply', 'renew'], $events);
	}

	public function test_it_maintains_the_lease_around_a_reverted_migration(): void {
		$events    = [];
		$migration = $this->createMock(Migration::class);
		$migration->method('id')->willReturn('2026_09_01_000001_create_reports');
		$migration->expects($this->once())
			->method('down')
			->willReturnCallback(static function (Schema $schema) use (&$events): void {
				$events[] = 'revert';
			});
		$session = new Session(new RecordingSchema(), $this->lease(2, $events));

		$session->revert($migration);

		$this->assertSame(['renew', 'revert', 'renew'], $events);
	}

	public function test_it_distinguishes_apply_failures_from_lease_renewal(): void {
		$events  = [];
		$session = new Session(new RecordingSchema(), $this->lease(1, $events));

		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while running');

		try {
			$session->apply(new FailingMigration('2026_09_01_000001_create_reports', failUp: true));
		} finally {
			$this->assertSame(['renew'], $events);
		}
	}

	public function test_it_distinguishes_revert_failures_from_lease_renewal(): void {
		$events  = [];
		$session = new Session(new RecordingSchema(), $this->lease(1, $events));

		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while rolling back');

		try {
			$session->revert(new FailingMigration('2026_09_01_000001_create_reports', failDown: true));
		} finally {
			$this->assertSame(['renew'], $events);
		}
	}

	/**
	 * Create a migration lease that records each expected renewal.
	 *
	 * @param list<string> $events
	 */
	private function lease(int $expectedRenewals, array &$events): Lease {
		$token = new LockToken(
			'nx-foundation-database-migrations',
			'owner',
			new DateTimeImmutable('+5 minutes')
		);
		$lock = $this->createMock(Lock::class);
		$lock->expects($this->exactly($expectedRenewals))
			->method('refresh')
			->with($token, 300)
			->willReturnCallback(static function () use (&$events, $token): LockToken {
				$events[] = 'renew';

				return $token;
			});

		return new Lease($lock, new TestDatabaseScope(), 1, $token, 300);
	}
}
