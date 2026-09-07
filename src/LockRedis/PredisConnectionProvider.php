<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockRedis;

use InvalidArgumentException;
use Predis\Client;
use Predis\ClientInterface;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\LockRedis\Connections\PredisConnection;
use StellarWP\Foundation\LockRedis\Contracts\Connection;

/**
 * Registers the dedicated Predis connection used by Redis-backed locks.
 */
final class PredisConnectionProvider extends Provider
{
	private const string CLIENT = self::class . '.client';

	/**
	 * Register one dedicated Predis client and its Foundation connection adapter.
	 *
	 * Predis interprets its configuration when the connection adapter is resolved.
	 *
	 * @throws ContainerException       When service bindings cannot be registered.
	 * @throws InvalidArgumentException When connection parameters are not configured.
	 */
	public function register(): void {
		if (! $this->config->has('lock.redis.parameters')) {
			throw new InvalidArgumentException(
				'Configure lock.redis.parameters before registering PredisConnectionProvider.'
			);
		}

		$parameters = $this->config->get('lock.redis.parameters');
		$options    = $this->config->get('lock.redis.options', []);

		$this->container->singleton(
			self::CLIENT,
			static fn (): Client => new Client($parameters, $options)
		);

		$this->container->when(PredisConnection::class)
			->needs(ClientInterface::class)
			->give(static fn (C $c): ClientInterface => $c->get(self::CLIENT));

		$this->container->singleton(PredisConnection::class);
		$this->container->singleton(
			Connection::class,
			static fn (C $c): PredisConnection => $c->get(PredisConnection::class)
		);
	}
}
