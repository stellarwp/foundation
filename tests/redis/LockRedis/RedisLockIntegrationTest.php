<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Redis\LockRedis;

use DateTimeImmutable;
use Predis\Client;
use Redis;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\SystemClock;
use StellarWP\Foundation\LockRedis\Connections\PhpRedisConnection;
use StellarWP\Foundation\LockRedis\Connections\PredisConnection;
use StellarWP\Foundation\LockRedis\RedisLock;
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
