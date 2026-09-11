<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\LockRedis;

use DateTimeImmutable;
use phpmock\mockery\PHPMockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Random\RandomException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\LockRedis\RedisLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\Support\Fixtures\LockRedis\RecordingConnection;
use StellarWP\Foundation\Tests\TestCase;

final class RedisLockFailureTest extends TestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_reports_unavailable_owner_entropy(): void {
		PHPMockery::mock('StellarWP\Foundation\Lock\Traits', 'random_bytes')
			->once()
			->andThrow(new RandomException('Entropy unavailable.'));
		$connection = new RecordingConnection();
		$lock       = new RedisLock(
			$connection,
			new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')),
			'tests:lock:'
		);

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Unable to generate a secure lock owner.');

		try {
			$lock->acquire('queue:sync', 60);
		} finally {
			$this->assertSame([], $connection->evaluateCalls);
		}
	}
}
