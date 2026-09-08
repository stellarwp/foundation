<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Lock;

use DateTimeImmutable;
use Error;
use RuntimeException;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockOwnershipLostException;
use StellarWP\Foundation\Lock\LockOperation;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\TestCase;

final class LockOperationTest extends TestCase
{
	public function test_it_runs_work_and_ignores_its_return_value_after_releasing_ownership(): void {
		$token = $this->token();
		$lock  = $this->mock(Lock::class);

		$lock->shouldReceive('acquire')->once()->with('catalog:42:sync', 300)->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andReturnTrue();

		$work_was_called = false;
		$argument_count  = null;

		$result = (new LockOperation($lock))->run(
			name: 'catalog:42:sync',
			ttl: 300,
			operation: static function () use (&$work_was_called, &$argument_count): false {
				$work_was_called = true;
				$argument_count  = func_num_args();

				return false;
			}
		);

		$this->assertTrue($result);
		$this->assertTrue($work_was_called);
		$this->assertSame(0, $argument_count);
	}

	public function test_it_returns_false_without_calling_work_when_another_owner_has_the_lock(): void {
		$lock = $this->mock(Lock::class);

		$lock->shouldReceive('acquire')->once()->with('catalog:42:sync', 300)->andReturnNull();
		$lock->shouldNotReceive('release');

		$work_was_called = false;

		$this->assertFalse((new LockOperation($lock))->run(
			name: 'catalog:42:sync',
			ttl: 300,
			operation: static function () use (&$work_was_called): void {
				$work_was_called = true;
			}
		));
		$this->assertFalse($work_was_called);
	}

	public function test_it_rethrows_work_failures_after_releasing_the_lock(): void {
		$token   = $this->token();
		$lock    = $this->mock(Lock::class);
		$failure = new RuntimeException('Synchronization failed.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andReturnTrue();

		$this->expectExceptionObject($failure);

		(new LockOperation($lock))->run('catalog:42:sync', 300, static function () use ($failure): void {
			throw $failure;
		});
	}

	public function test_it_preserves_a_work_exception_when_cleanup_fails(): void {
		$token   = $this->token();
		$lock    = $this->mock(Lock::class);
		$failure = new RuntimeException('Synchronization failed.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andThrow(new RuntimeException('Redis unavailable.'));

		$this->expectExceptionObject($failure);

		(new LockOperation($lock))->run('catalog:42:sync', 300, static function () use ($failure): void {
			throw $failure;
		});
	}

	public function test_it_preserves_a_work_error_when_cleanup_fails(): void {
		$token   = $this->token();
		$lock    = $this->mock(Lock::class);
		$failure = new Error('Synchronization failed.');

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andThrow(new RuntimeException('Redis unavailable.'));

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Synchronization failed.');

		(new LockOperation($lock))->run('catalog:42:sync', 300, static function () use ($failure): void {
			throw $failure;
		});
	}

	public function test_it_reports_lost_ownership_when_successful_work_cannot_be_released(): void {
		$token = $this->token();
		$lock  = $this->mock(Lock::class);

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andReturnFalse();

		$this->expectException(LockOwnershipLostException::class);
		$this->expectExceptionMessage('Protected work lost lock ownership.');

		(new LockOperation($lock))->run('catalog:42:sync', 300, static fn (): null => null);
	}

	public function test_it_propagates_acquisition_failures_without_calling_work(): void {
		$lock = $this->mock(Lock::class);

		$lock->shouldReceive('acquire')->once()->andThrow(new RuntimeException('Redis unavailable.'));
		$lock->shouldNotReceive('release');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Redis unavailable.');

		(new LockOperation($lock))->run('catalog:42:sync', 300, static fn (): null => null);
	}

	public function test_it_propagates_release_failures_after_successful_work(): void {
		$token = $this->token();
		$lock  = $this->mock(Lock::class);

		$lock->shouldReceive('acquire')->once()->andReturn($token);
		$lock->shouldReceive('release')->once()->with($token)->andThrow(new RuntimeException('Redis unavailable.'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Redis unavailable.');

		(new LockOperation($lock))->run('catalog:42:sync', 300, static fn (): null => null);
	}

	private function token(): LockToken {
		return new LockToken(
			name: 'catalog:42:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:05:00')
		);
	}
}
