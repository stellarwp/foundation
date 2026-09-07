<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\LockRedis;

use InvalidArgumentException;
use Predis\ClientInterface;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\LockRedis\Connections\PredisConnection;
use StellarWP\Foundation\LockRedis\Contracts\Connection;
use StellarWP\Foundation\LockRedis\LockRedisProvider;
use StellarWP\Foundation\LockRedis\PredisConnectionProvider;
use StellarWP\Foundation\LockRedis\RedisLock;
use StellarWP\Foundation\Tests\Support\Fixtures\LockRedis\RecordingConnection;
use StellarWP\Foundation\Tests\TestCase;

final class PredisConnectionProviderTest extends TestCase
{
	public function test_it_registers_a_dedicated_predis_client_without_replacing_a_shared_client(): void {
		$shared_client = $this->mock(ClientInterface::class);

		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'lock' => ['redis' => [
				'parameters' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
			]],
		]));
		$this->container->singleton(ClientInterface::class, $shared_client);
		$this->container->register(PredisConnectionProvider::class);

		$connection = $this->container->get(Connection::class);

		$this->assertInstanceOf(PredisConnection::class, $connection);
		$this->assertSame($shared_client, $this->container->get(ClientInterface::class));
	}

	public function test_it_accepts_a_single_endpoint_dsn_and_optional_client_options(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'lock' => ['redis' => [
				'parameters' => 'tcp://127.0.0.1:6379?database=1',
				'options'    => ['exceptions' => true],
			]],
		]));
		$this->container->register(PredisConnectionProvider::class);

		$this->assertInstanceOf(PredisConnection::class, $this->container->get(Connection::class));
	}

	public function test_an_application_can_replace_the_connection_after_provider_registration(): void {
		$connection = new RecordingConnection();

		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'foundation' => ['prefix' => 'your-plugin'],
			'lock'       => ['redis' => ['parameters' => ['host' => '127.0.0.1']]],
		]));
		$this->container->register(PredisConnectionProvider::class);
		$this->container->bind(Connection::class, $connection);
		$this->container->register(LockRedisProvider::class);

		$token = $this->container->get(RedisLock::class)->acquire('catalog:42:sync', 300);

		$this->assertNotNull($token);
		$this->assertSame(['your-plugin:lock:catalog:42:sync'], $connection->evaluateCalls[0]['keys']);
	}

	public function test_it_requires_connection_parameters_during_registration(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Configure lock.redis.parameters before registering PredisConnectionProvider.');

		$this->container->register(PredisConnectionProvider::class);
	}

	public function test_predis_configuration_errors_surface_when_the_connection_is_resolved(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'lock' => ['redis' => [
				'parameters' => ['host' => '127.0.0.1'],
				'options'    => 'invalid-options',
			]],
		]));
		$this->container->register(PredisConnectionProvider::class);

		try {
			$this->container->get(Connection::class);
			$this->fail('Invalid Predis options must fail during resolution.');
		} catch (ContainerException $exception) {
			while ($exception->getPrevious() !== null) {
				$exception = $exception->getPrevious();
			}

			$this->assertInstanceOf(InvalidArgumentException::class, $exception);
		}
	}
}
