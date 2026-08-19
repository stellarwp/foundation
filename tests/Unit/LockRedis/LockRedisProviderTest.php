<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\LockRedis;

use Adbar\Dot;
use InvalidArgumentException;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\LockRedis\Contracts\Connection;
use StellarWP\Foundation\LockRedis\LockRedisProvider;
use StellarWP\Foundation\LockRedis\RedisLock;
use StellarWP\Foundation\Tests\Support\Fixtures\LockRedis\RecordingConnection;
use StellarWP\Foundation\Tests\TestCase;

final class LockRedisProviderTest extends TestCase
{
	public function test_it_registers_a_redis_lock_with_the_configured_prefix(): void {
		$connection = new RecordingConnection();

		$this->container->get(Dot::class)->set('lock.redis.prefix', 'provider:lock:');
		$this->container->bind(Connection::class, $connection);
		$this->container->register(LockRedisProvider::class);

		$lock  = $this->container->get(RedisLock::class);
		$token = $lock->acquire('queue:sync', 60);

		$this->assertSame($lock, $this->container->get(RedisLock::class));
		$this->assertNotNull($token);
		$this->assertSame(['provider:lock:queue:sync'], $connection->evaluateCalls[0]['keys']);
	}

	public function test_it_does_not_bind_the_generic_lock_contract(): void {
		$this->container->get(Dot::class)->set('lock.redis.prefix', 'provider:lock:');
		$this->container->register(LockRedisProvider::class);

		$this->assertFalse($this->container->has(Lock::class));
	}

	public function test_it_requires_an_explicit_prefix(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The lock.redis.prefix configuration value must be a non-empty string.');

		$this->container->register(LockRedisProvider::class);
	}
}
