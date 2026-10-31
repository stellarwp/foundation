<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\LockRedis;

use Redis;
use RedisException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\LockRedis\Connections\PhpRedisConnection;
use StellarWP\Foundation\Tests\TestCase;

final class PhpRedisConnectionTest extends TestCase
{
	public function test_it_rejects_non_integer_exists_responses(): void {
		$redis = $this->mock(Redis::class);
		$redis->shouldReceive('clearLastError')->once()->andReturnTrue();
		$redis->shouldReceive('exists')->once()->andReturnFalse();
		$redis->shouldReceive('getLastError')->once()->andReturnNull();

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('PhpRedis returned an unexpected EXISTS response.');

		(new PhpRedisConnection($redis))->exists('lock');
	}

	public function test_it_wraps_phpredis_exceptions(): void {
		$redis = $this->mock(Redis::class);
		$redis->shouldReceive('clearLastError')->once()->andReturnTrue();
		$redis->shouldReceive('exists')->once()->andThrow(new RedisException('connection lost'));

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('PhpRedis could not execute the lock operation.');

		(new PhpRedisConnection($redis))->exists('lock');
	}

	public function test_it_rejects_phpredis_command_errors(): void {
		$redis = $this->mock(Redis::class);
		$redis->shouldReceive('clearLastError')->once()->andReturnTrue();
		$redis->shouldReceive('exists')->once()->andReturnFalse();
		$redis->shouldReceive('getLastError')->once()->andReturn('ERR connection lost');

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('PhpRedis could not execute the lock operation: ERR connection lost');

		(new PhpRedisConnection($redis))->exists('lock');
	}
}
