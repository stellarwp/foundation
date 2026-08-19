# Foundation Lock Redis

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

## Installation

```shell
composer require stellarwp/foundation-lock-redis
```

Install one supported Redis client:

```shell
composer require "predis/predis:>=3.0 <4.0"
```

Alternatively, install and enable the PhpRedis extension.

## Usage

`RedisLock` implements Foundation's shared lock contract with atomic Redis
acquisition, release, and refresh operations. Applications must provide a
dedicated Redis connection and an application-specific key prefix.

Map the Redis connection and lock settings in the application's `config.php`:

```php
<?php declare(strict_types=1);

return [
	'lock' => [
		'redis' => [
			'host'     => $_ENV['FOUNDATION_LOCK_REDIS_HOST'] ?? '127.0.0.1',
			'port'     => (int) ($_ENV['FOUNDATION_LOCK_REDIS_PORT'] ?? 6379),
			'database' => (int) ($_ENV['FOUNDATION_LOCK_REDIS_DATABASE'] ?? 1),
			'prefix'   => $_ENV['FOUNDATION_LOCK_REDIS_PREFIX'] ?? 'acme:site-123:lock:',
		],
	],
];
```

After [registering `config.php` with the Foundation container](https://github.com/stellarwp/foundation-container#making-a-configphp),
providers receive its configured `Dot` instance through `$this->config`. Use
those values when binding the application's Redis client:

```php
use lucatume\DI52\Container as C;
use Predis\Client;
use Predis\ClientInterface;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\LockRedis\Connections\PredisConnection;
use StellarWP\Foundation\LockRedis\Contracts\Connection;
use StellarWP\Foundation\LockRedis\LockRedisProvider;
use StellarWP\Foundation\LockRedis\RedisLock;

final class RedisProvider extends Provider
{
	public function register(): void {
		$this->container->when(PredisConnection::class)
			->needs(ClientInterface::class)
			->give(fn (): ClientInterface => new Client([
				'host'     => (string) $this->config->get('lock.redis.host'),
				'port'     => (int) $this->config->get('lock.redis.port'),
				'database' => (int) $this->config->get('lock.redis.database'),
			]));

		$this->container->singleton(PredisConnection::class);
		$this->container->bind(Connection::class, static fn (C $c): PredisConnection => $c->get(PredisConnection::class));
		$this->container->register(LockRedisProvider::class);

		$this->container->bind(Lock::class, static fn (C $c): RedisLock => $c->get(RedisLock::class));
	}
}
```

For PhpRedis, bind a separately configured `Redis` instance and select the
PhpRedis adapter instead:

```php
use lucatume\DI52\Container as C;
use Redis;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\LockRedis\Connections\PhpRedisConnection;
use StellarWP\Foundation\LockRedis\Contracts\Connection;
use StellarWP\Foundation\LockRedis\LockRedisProvider;
use StellarWP\Foundation\LockRedis\RedisLock;

final class RedisProvider extends Provider
{
	public function register(): void {
		$this->container->when(PhpRedisConnection::class)
			->needs(Redis::class)
			->give(function (): Redis {
				$redis = new Redis();
				$redis->connect(
					(string) $this->config->get('lock.redis.host'),
					(int) $this->config->get('lock.redis.port')
				);
				$redis->select((int) $this->config->get('lock.redis.database'));

				return $redis;
			});

		$this->container->singleton(PhpRedisConnection::class);
		$this->container->bind(Connection::class, static fn (C $c): PhpRedisConnection => $c->get(PhpRedisConnection::class));
		$this->container->register(LockRedisProvider::class);

		$this->container->bind(Lock::class, static fn (C $c): RedisLock => $c->get(RedisLock::class));
	}
}
```

The package never selects a Redis database or reuses WordPress object-cache
globals. Supply a separate client connection. A separate logical database
protects locks from `FLUSHDB` issued against the object-cache database, but it
does not protect against `FLUSHALL`, eviction, restart, or failover. Use a
separate Redis endpoint when stronger isolation is required. Redis Cluster
supports only database `0`, so endpoint isolation is required there. The
package supports a single writable Redis endpoint; Redis Cluster and Sentinel
are not currently supported or tested.

Lock contention is not an infrastructure failure: `acquire()` returns `null`
when another owner holds the lock. `release()` returns `false`, and `refresh()`
returns `null`, when the token no longer owns the lease. Uncertain Redis
results throw
`StellarWP\Foundation\Lock\Exceptions\LockUnavailableException`; callers
should fail closed instead of continuing the protected work without a lock.

Redis locks are expiring leases, not exactly-once guarantees. The TTL must
cover the protected work or be refreshed before it expires. External side
effects such as payment requests should also use provider-supported
idempotency keys. Asynchronous Redis failover, eviction, restart, or
administrative key removal can permit overlapping owners.
