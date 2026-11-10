<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Lock;

use DateTimeImmutable;
use Error;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockContendedException;
use StellarWP\Foundation\Lock\Exceptions\LockOwnershipLostException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockLease;
use StellarWP\Foundation\Lock\LockOperation;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;
use Throwable;

final class LockOperationTest extends TestCase
{
	public function test_it_returns_false_and_null_results_without_confusing_them_with_contention(): void {
		$lock      = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		$operation = new LockOperation($lock);

		foreach ([false, null] as $result) {
			$this->assertSame($result, $operation->run('catalog:42:sync', 300, static fn () => $result));
			$this->assertFalse($lock->isAcquired('catalog:42:sync'));
		}
	}

	public function test_it_preserves_callback_result_identity_after_releasing_ownership(): void {
		$lock   = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		$result = new stdClass();

		$this->assertSame($result, (new LockOperation($lock))->run(
			name: 'catalog:42:sync',
			ttl: 300,
			operation: static fn (): stdClass => $result
		));
		$this->assertFalse($lock->isAcquired('catalog:42:sync'));
	}

	public function test_it_reports_contention_without_calling_work_or_retrying(): void {
		$lock = $this->mock(Lock::class);

		$lock->shouldReceive('acquire')->once()->with('catalog:42:sync', 300)->andReturnNull();
		$lock->shouldNotReceive('refresh');
		$lock->shouldNotReceive('release');

		$failure = $this->failureFrom(fn () => (new LockOperation($lock))->run(
			name: 'catalog:42:sync',
			ttl: 300,
			operation: function (): void {
				$this->fail('Contended work must not execute.');
			}
		));

		$this->assertInstanceOf(LockContendedException::class, $failure);
	}

	public function test_it_rethrows_work_failures_after_releasing_the_lock(): void {
		$token   = $this->token();
		$lock    = $this->mock(Lock::class);
		$failure = new RuntimeException('Synchronization failed.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andReturnTrue();

		$this->assertSame($failure, $this->failureFrom(static fn () => (new LockOperation($lock))->run(
			'catalog:42:sync',
			300,
			static fn () => throw $failure
		)));
	}

	/**
	 * @dataProvider failedCleanup
	 */
	#[DataProvider('failedCleanup')]
	public function test_it_preserves_a_work_exception_when_cleanup_fails(bool $cleanup_throws): void {
		$token   = $this->token();
		$lock    = $this->mock(Lock::class);
		$failure = new RuntimeException('Synchronization failed.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$release = $lock->shouldReceive('release')->once()->with($token);

		if ($cleanup_throws) {
			$release->andThrow(new RuntimeException('Redis unavailable.'));
		} else {
			$release->andReturnFalse();
		}

		$this->assertSame($failure, $this->failureFrom(static fn () => (new LockOperation($lock))->run(
			'catalog:42:sync',
			300,
			static fn () => throw $failure
		)));
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function failedCleanup(): array {
		return [
			'ownership lost' => [false],
			'backend failed' => [true],
		];
	}

	public function test_it_preserves_a_work_error_when_cleanup_fails(): void {
		$token   = $this->token();
		$lock    = $this->mock(Lock::class);
		$failure = new Error('Synchronization failed.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andThrow(new RuntimeException('Redis unavailable.'));

		$this->assertSame($failure, $this->failureFrom(static fn () => (new LockOperation($lock))->run(
			'catalog:42:sync',
			300,
			static fn () => throw $failure
		)));
	}

	public function test_it_reports_lost_ownership_when_successful_work_cannot_be_released(): void {
		$token = $this->token();
		$lock  = $this->mock(Lock::class);

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andReturnFalse();

		$this->expectException(LockOwnershipLostException::class);

		(new LockOperation($lock))->run('catalog:42:sync', 300, static fn (): null => null);
	}

	public function test_it_propagates_acquisition_failures_without_calling_work(): void {
		$lock    = $this->mock(Lock::class);
		$failure = new RuntimeException('Redis unavailable.');

		$lock->shouldReceive('acquire')->once()->andThrow($failure);
		$lock->shouldNotReceive('release');

		$this->assertSame($failure, $this->failureFrom(fn () => (new LockOperation($lock))->run(
			'catalog:42:sync',
			300,
			function (): void {
				$this->fail('Work must not execute after acquisition fails.');
			}
		)));
	}

	public function test_it_propagates_release_failures_after_successful_work(): void {
		$token   = $this->token();
		$lock    = $this->mock(Lock::class);
		$failure = new RuntimeException('Redis unavailable.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andThrow($failure);

		$this->assertSame($failure, $this->failureFrom(static fn () => (new LockOperation($lock))->run(
			'catalog:42:sync',
			300,
			static fn (): null => null
		)));
	}

	public function test_it_renews_and_releases_with_the_latest_backend_token(): void {
		$token   = $this->token();
		$renewed = $token->withExpiration(new DateTimeImmutable('2026-01-01 00:09:00'));
		$latest  = $token->withExpiration(new DateTimeImmutable('2026-01-01 00:13:00'));
		$lock    = $this->mock(Lock::class);
		$result  = new stdClass();

		$lock->shouldReceive('acquire')->once()->with('catalog:42:sync', 300)->andReturn($token);
		$lock->shouldReceive('refresh')->once()->with($token, 300)->andReturn($renewed);
		$lock->shouldReceive('refresh')->once()->with($renewed, 300)->andReturn($latest);
		$lock->shouldReceive('release')->once()->with($latest)->andReturnTrue();

		$this->assertSame($result, (new LockOperation($lock))->run(
			'catalog:42:sync',
			300,
			static function (LockLease $lease) use ($result): stdClass {
				$lease->renew();
				$lease->renew();

				return $result;
			}
		));
	}

	/**
	 * @dataProvider renewalFailureOutcomes
	 */
	#[DataProvider('renewalFailureOutcomes')]
	public function test_a_swallowed_renewal_failure_is_terminal_regardless_of_cleanup(bool $refresh_throws, ?bool $released): void {
		$token           = $this->token();
		$lock            = $this->mock(Lock::class);
		$backend_failure = new LockUnavailableException('Refresh unavailable.');
		$first_failure   = null;
		$captured_lease  = null;

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$refresh = $lock->shouldReceive('refresh')->once()->with($token, 300);

		if ($refresh_throws) {
			$refresh->andThrow($backend_failure);
		} else {
			$refresh->andReturnNull();
		}

		$release = $lock->shouldReceive('release')->once()->with($token);

		if ($released === null) {
			$release->andThrow(new RuntimeException('Release unavailable.'));
		} else {
			$release->andReturn($released);
		}

		$failure = $this->failureFrom(function () use ($lock, &$first_failure, &$captured_lease): void {
			(new LockOperation($lock))->run('catalog:42:sync', 300, function (LockLease $lease) use (&$first_failure, &$captured_lease): bool {
				$captured_lease = $lease;
				$first_failure  = $this->failureFrom(static fn () => $lease->renew());
				$this->assertSame($first_failure, $this->failureFrom(static fn () => $lease->renew()));

				return true;
			});
		});

		$this->assertSame($first_failure, $failure);

		if ($refresh_throws) {
			$this->assertSame($backend_failure, $failure);
		} else {
			$this->assertInstanceOf(LockOwnershipLostException::class, $failure);
		}
		$this->assertInstanceOf(LockLease::class, $captured_lease);
		$this->assertInstanceOf(LogicException::class, $this->failureFrom(static fn () => $captured_lease->renew()));
	}

	/**
	 * @return array<string, array{bool, ?bool}>
	 */
	public static function renewalFailureOutcomes(): array {
		return [
			'lost ownership, released'          => [false, true],
			'lost ownership, release rejected'  => [false, false],
			'lost ownership, release threw'     => [false, null],
			'backend failure, released'         => [true, true],
			'backend failure, release rejected' => [true, false],
			'backend failure, release threw'    => [true, null],
		];
	}

	public function test_an_escaping_callback_failure_wins_over_an_earlier_renewal_failure_and_cleanup_failure(): void {
		$token            = $this->token();
		$lock             = $this->mock(Lock::class);
		$callback_failure = new Error('Import failed after renewal.');
		$renewal_failure  = new RuntimeException('Refresh unavailable.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('refresh')->once()->andThrow($renewal_failure);
		$lock->shouldReceive('release')->once()->with($token)->andThrow(new RuntimeException('Release unavailable.'));

		$failure = $this->failureFrom(fn () => (new LockOperation($lock))->run(
			'catalog:42:sync',
			300,
			function (LockLease $lease) use ($callback_failure, $renewal_failure): void {
				$this->assertSame($renewal_failure, $this->failureFrom(static fn () => $lease->renew()));

				throw $callback_failure;
			}
		));

		$this->assertSame($callback_failure, $failure);
	}

	/**
	 * @dataProvider closedLeaseOutcomes
	 */
	#[DataProvider('closedLeaseOutcomes')]
	public function test_captured_leases_cannot_renew_during_or_after_cleanup(bool $work_fails, ?bool $released): void {
		$token           = $this->token();
		$lock            = $this->mock(Lock::class);
		$captured_lease  = null;
		$work_failure    = new Error('Import failed.');
		$release_failure = new RuntimeException('Release unavailable.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldNotReceive('refresh');
		$lock->shouldReceive('release')->once()->with($token)->andReturnUsing(function () use (&$captured_lease, $released, $release_failure): bool {
			$this->assertInstanceOf(LockLease::class, $captured_lease);
			$this->assertInstanceOf(LogicException::class, $this->failureFrom(static fn () => $captured_lease->renew()));

			if ($released === null) {
				throw $release_failure;
			}

			return $released;
		});

		$run = static function () use ($lock, &$captured_lease, $work_fails, $work_failure): mixed {
			return (new LockOperation($lock))->run('catalog:42:sync', 300, static function (LockLease $lease) use (&$captured_lease, $work_fails, $work_failure): string {
				$captured_lease = $lease;

				if ($work_fails) {
					throw $work_failure;
				}

				return 'imported';
			});
		};

		if ($work_fails) {
			$this->assertSame($work_failure, $this->failureFrom($run));
		} elseif ($released === null) {
			$this->assertSame($release_failure, $this->failureFrom($run));
		} elseif (! $released) {
			$this->assertInstanceOf(LockOwnershipLostException::class, $this->failureFrom($run));
		} else {
			$this->assertSame('imported', $run());
		}

		$this->assertInstanceOf(LockLease::class, $captured_lease);
		$this->assertInstanceOf(LogicException::class, $this->failureFrom(static fn () => $captured_lease->renew()));
	}

	/**
	 * @return array<string, array{bool, ?bool}>
	 */
	public static function closedLeaseOutcomes(): array {
		return [
			'successful work'  => [false, true],
			'failed work'      => [true, true],
			'release rejected' => [false, false],
			'release threw'    => [false, null],
		];
	}

	private function failureFrom(callable $operation): Throwable {
		try {
			$operation();
		} catch (Throwable $failure) {
			return $failure;
		}

		$this->fail('The operation must fail.');
	}

	private function token(): LockToken {
		return new LockToken(
			name: 'catalog:42:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:05:00')
		);
	}
}
