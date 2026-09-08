<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Redis\LockRedis;

use DateTimeImmutable;
use Predis\Client;
use Predis\ClientInterface;
use Redis;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockOperation;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\SystemClock;
use StellarWP\Foundation\LockRedis\Connections\PhpRedisConnection;
use StellarWP\Foundation\LockRedis\Connections\PredisConnection;
use StellarWP\Foundation\LockRedis\Contracts\Connection;
use StellarWP\Foundation\LockRedis\LockRedisProvider;
use StellarWP\Foundation\LockRedis\PredisConnectionProvider;
use StellarWP\Foundation\LockRedis\RedisLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\Catalog_Importer;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\Catalog_Synchronizer;
use StellarWP\Foundation\Tests\Support\Fixtures\LockRedis\RecordingConnection;
use StellarWP\Foundation\Tests\TestCase;

final class RedisLockIntegrationTest extends TestCase
{
	private Redis $phpRedis;

	private Client $predis;

	private RedisLock $phpRedisLock;

	private RedisLock $predisLock;

	private string $prefix;

	protected function setUp(): void {
		parent::setUp();

		$host         = (string) ($_ENV['REDIS_HOST'] ?? 'redis');
		$port         = (int) ($_ENV['REDIS_PORT'] ?? 6379);
		$database     = (int) ($_ENV['REDIS_TEST_DATABASE'] ?? 15);
		$this->prefix = sprintf('tests:lock:%s:', str_replace('.', '', uniqid('', true)));

		$this->phpRedis = new Redis();
		$this->phpRedis->connect($host, $port);
		$this->phpRedis->select($database);

		$this->predis = new Client([
			'scheme'   => 'tcp',
			'host'     => $host,
			'port'     => $port,
			'database' => $database,
		]);

		$this->phpRedisLock = new RedisLock(new PhpRedisConnection($this->phpRedis), new SystemClock(), $this->prefix);
		$this->predisLock   = new RedisLock(new PredisConnection($this->predis), new SystemClock(), $this->prefix);
	}

	protected function tearDown(): void {
		$this->predis->disconnect();
		$this->phpRedis->close();

		parent::tearDown();
	}

	public function test_phpredis_and_predis_coordinate_the_same_lock(): void {
		$first = $this->phpRedisLock->acquire('queue:sync', 10);

		$this->assertInstanceOf(LockToken::class, $first);
		$this->assertNull($this->predisLock->acquire('queue:sync', 10));
		$this->assertTrue($this->predisLock->isAcquired('queue:sync'));
		$this->assertFalse($this->predisLock->release(new LockToken(
			name: 'queue:sync',
			owner: 'another-owner',
			expiresAt: new DateTimeImmutable('+10 seconds')
		)));
		$this->assertTrue($this->phpRedisLock->release($first));

		$second = $this->predisLock->acquire('queue:sync', 10);

		$this->assertInstanceOf(LockToken::class, $second);
		$this->assertFalse($this->phpRedisLock->release($first));
		$this->assertTrue($this->predisLock->release($second));
	}

	public function test_an_expired_owner_cannot_modify_its_replacement(): void {
		$expired = $this->predisLock->acquire('queue:sync', 1);

		$this->assertInstanceOf(LockToken::class, $expired);

		sleep(2);

		$replacement = $this->phpRedisLock->acquire('queue:sync', 10);

		$this->assertInstanceOf(LockToken::class, $replacement);
		$this->assertNull($this->predisLock->refresh($expired, 10));
		$this->assertFalse($this->predisLock->release($expired));
		$this->assertTrue($this->phpRedisLock->isAcquired('queue:sync'));
		$this->assertTrue($this->phpRedisLock->release($replacement));
	}

	public function test_refresh_extends_the_authoritative_redis_lease(): void {
		$token = $this->phpRedisLock->acquire('queue:sync', 1);

		$this->assertInstanceOf(LockToken::class, $token);

		$refreshed = $this->predisLock->refresh($token, 3);

		$this->assertInstanceOf(LockToken::class, $refreshed);

		sleep(2);

		$this->assertTrue($this->phpRedisLock->isAcquired('queue:sync'));
		$this->assertTrue($this->predisLock->release($refreshed));
	}

	public function test_predis_errors_fail_closed(): void {
		$this->expectException(LockUnavailableException::class);

		(new PredisConnection($this->predis))->evaluate('not valid lua', [], []);
	}

	public function test_predis_connection_provider_uses_its_dedicated_client(): void {
		$shared_client      = $this->mock(ClientInterface::class);
		$unreachable_client = new Client('tcp://127.0.0.1:1');

		$this->container->singleton(Client::class, $unreachable_client);
		$this->container->singleton(ClientInterface::class, $shared_client);
		$this->registerRedisProviders();

		$lock  = $this->container->get(RedisLock::class);
		$token = $lock->acquire('provider:sync', 10);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertSame($shared_client, $this->container->get(ClientInterface::class));
		$this->assertSame($unreachable_client, $this->container->get(Client::class));
		$this->assertInstanceOf(PredisConnection::class, $this->container->get(Connection::class));
		$this->assertTrue($lock->release($token));
	}

	public function test_the_application_consumer_coordinates_through_the_selected_redis_backend(): void {
		$this->registerRedisProviders();
		$this->container->singleton(Lock::class, static fn (C $c): RedisLock => $c->get(RedisLock::class));
		$importer = new Catalog_Importer();
		$this->container->singleton(Catalog_Importer::class, $importer);
		$consumer    = $this->container->get(Catalog_Synchronizer::class);
		$other_owner = $this->phpRedisLock->acquire('catalog:42:sync', 30);
		$this->assertInstanceOf(LockToken::class, $other_owner);

		try {
			$this->assertFalse($consumer->synchronize(42));
			$this->assertSame([], $importer->imported_site_ids);
		} finally {
			$this->phpRedisLock->release($other_owner);
		}

		$this->assertTrue($consumer->synchronize(42));
		$this->assertSame([42], $importer->imported_site_ids);
		$this->assertFalse($this->phpRedisLock->isAcquired('catalog:42:sync'));
	}

	public function test_a_feature_can_use_redis_while_another_backend_remains_the_default(): void {
		$default_lock = new InMemoryLock(new SystemClock());
		$this->container->singleton(Lock::class, $default_lock);
		$this->registerRedisProviders();
		$this->container->when(Catalog_Synchronizer::class)
			->needs(LockOperation::class)
			->give(static fn (C $c): LockOperation => new LockOperation($c->get(RedisLock::class)));
		$importer = new Catalog_Importer();
		$this->container->singleton(Catalog_Importer::class, $importer);
		$consumer = $this->container->get(Catalog_Synchronizer::class);

		$this->assertNotNull($default_lock->acquire('catalog:42:sync', 300));
		$this->assertSame($default_lock, $this->container->get(Lock::class));
		$this->assertFalse($this->container->get(LockOperation::class)->run(
			'catalog:42:sync',
			300,
			function (): void {
				$this->fail('The application default must still observe its occupied lock.');
			}
		));
		$this->assertTrue($consumer->synchronize(42));

		$other_owner = $this->phpRedisLock->acquire('catalog:42:sync', 30);
		$this->assertInstanceOf(LockToken::class, $other_owner);

		try {
			$this->assertFalse($consumer->synchronize(42));
			$this->assertSame([42], $importer->imported_site_ids);
		} finally {
			$this->phpRedisLock->release($other_owner);
		}
	}

	private function registerRedisProviders(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'lock' => ['redis' => [
				'prefix'     => $this->prefix,
				'parameters' => [
					'host'     => (string) ($_ENV['REDIS_HOST'] ?? 'redis'),
					'port'     => (int) ($_ENV['REDIS_PORT'] ?? 6379),
					'database' => (int) ($_ENV['REDIS_TEST_DATABASE'] ?? 15),
				],
			]],
		]));
		$this->container->register(PredisConnectionProvider::class);
		$this->container->register(LockRedisProvider::class);
	}

	public function test_phpredis_errors_fail_closed(): void {
		$this->expectException(LockUnavailableException::class);

		(new PhpRedisConnection($this->phpRedis))->evaluate('return false', [], []);
	}

	public function test_phpredis_serialization_does_not_change_lock_ownership(): void {
		$this->phpRedis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

		$this->assertPhpRedisOwnerCanRefreshAndRelease();
	}

	public function test_phpredis_compression_does_not_change_lock_ownership(): void {
		$this->phpRedis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);

		$this->assertPhpRedisOwnerCanRefreshAndRelease();
	}

	public function test_retried_acquisition_recognizes_the_same_owner_without_extending_the_ttl(): void {
		$recording = new RecordingConnection();
		$token     = (new RedisLock($recording, new SystemClock(), $this->prefix))->acquire('queue:retry', 10);

		$this->assertInstanceOf(LockToken::class, $token);

		$call       = $recording->evaluateCalls[0];
		$connection = new PhpRedisConnection($this->phpRedis);

		$this->assertSame(1, $connection->evaluate($call['script'], $call['keys'], $call['arguments']));

		sleep(2);

		$this->assertSame(1, $connection->evaluate($call['script'], $call['keys'], $call['arguments']));
		$this->assertLessThan(10, $this->phpRedis->ttl($call['keys'][0]));
		$this->assertTrue($this->phpRedisLock->release($token));
	}

	private function assertPhpRedisOwnerCanRefreshAndRelease(): void {
		$token = $this->phpRedisLock->acquire('queue:sync', 10);

		$this->assertInstanceOf(LockToken::class, $token);

		$refreshed = $this->phpRedisLock->refresh($token, 20);

		$this->assertInstanceOf(LockToken::class, $refreshed);
		$this->assertTrue($this->phpRedisLock->release($refreshed));
	}
}
