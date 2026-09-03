<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\LockRedis;

use DateTimeImmutable;
use InvalidArgumentException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\LockRedis\RedisLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\Support\Fixtures\LockRedis\RecordingConnection;
use StellarWP\Foundation\Tests\TestCase;

final class RedisLockTest extends TestCase
{
	private MutableClock $clock;

	private RecordingConnection $connection;

	private RedisLock $lock;

	protected function setUp(): void {
		parent::setUp();

		$this->clock      = new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00'));
		$this->connection = new RecordingConnection();
		$this->lock       = new RedisLock($this->connection, $this->clock, 'tests:lock:');
	}

	public function test_it_acquires_a_prefixed_lock_with_a_conservative_expiration(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertSame('queue:sync', $token->name);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token->owner);
		$this->assertSame('2026-01-01 00:01:00', $token->expiresAt->format('Y-m-d H:i:s'));
		$this->assertSame([[
			'script'    => $this->connection->evaluateCalls[0]['script'],
			'keys'      => ['tests:lock:queue:sync'],
			'arguments' => [$token->owner, 60],
		]], $this->connection->evaluateCalls);
		$this->assertStringContainsString("redis.call('SET'", $this->connection->evaluateCalls[0]['script']);
	}

	public function test_it_returns_null_when_the_lock_is_contended(): void {
		$this->connection->evaluateResult = 0;

		$this->assertNull($this->lock->acquire('queue:sync', 60));
	}

	public function test_it_rejects_an_unexpected_acquisition_result(): void {
		$this->connection->evaluateResult = 2;

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Redis returned an unexpected acquisition result.');

		$this->lock->acquire('queue:sync', 60);
	}

	public function test_it_releases_only_the_matching_owner(): void {
		$token = $this->token();

		$this->assertTrue($this->lock->release($token));
		$this->assertSame(['tests:lock:queue:sync'], $this->connection->evaluateCalls[0]['keys']);
		$this->assertSame(['owner'], $this->connection->evaluateCalls[0]['arguments']);

		$this->connection->evaluateResult = 0;

		$this->assertFalse($this->lock->release($token));
	}

	public function test_it_refreshes_only_the_matching_owner(): void {
		$token = $this->token();

		$this->clock->advance(30);

		$refreshed = $this->lock->refresh($token, 120);

		$this->assertInstanceOf(LockToken::class, $refreshed);
		$this->assertSame('2026-01-01 00:02:30', $refreshed->expiresAt->format('Y-m-d H:i:s'));
		$this->assertSame(['tests:lock:queue:sync'], $this->connection->evaluateCalls[0]['keys']);
		$this->assertSame(['owner', 120], $this->connection->evaluateCalls[0]['arguments']);

		$this->connection->evaluateResult = 0;

		$this->assertNull($this->lock->refresh($token, 120));
	}

	public function test_it_reports_prefixed_lock_existence(): void {
		$this->connection->existsResult = true;

		$this->assertTrue($this->lock->isAcquired('queue:sync'));
		$this->assertSame(['tests:lock:queue:sync'], $this->connection->existsCalls);
	}

	public function test_it_rejects_an_empty_prefix(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Redis lock prefix cannot be empty.');

		new RedisLock($this->connection, $this->clock, '');
	}

	public function test_it_rejects_an_empty_lock_name(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock name cannot be empty.');

		$this->lock->acquire('', 60);
	}

	public function test_it_rejects_an_invalid_acquisition_ttl(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock TTL must be greater than zero seconds.');

		$this->lock->acquire('queue:sync', 0);
	}

	public function test_it_rejects_an_invalid_refresh_ttl(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock TTL must be greater than zero seconds.');

		$this->lock->refresh($this->token(), 0);
	}

	public function test_it_does_not_acquire_when_the_ttl_cannot_be_represented(): void {
		try {
			$this->lock->acquire('queue:sync', 1_000_000_000_000);
			$this->fail('Expected an invalid TTL exception.');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('Lock TTL cannot be represented.', $exception->getMessage());
			$this->assertSame([], $this->connection->evaluateCalls);
		}
	}

	public function test_it_does_not_refresh_when_the_ttl_cannot_be_represented(): void {
		try {
			$this->lock->refresh($this->token(), 1_000_000_000_000);
			$this->fail('Expected an invalid TTL exception.');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('Lock TTL cannot be represented.', $exception->getMessage());
			$this->assertSame([], $this->connection->evaluateCalls);
		}
	}

	public function test_it_rejects_an_empty_name_when_checking_existence(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock name cannot be empty.');

		$this->lock->isAcquired('');
	}

	public function test_it_rejects_an_unexpected_release_result(): void {
		$this->connection->evaluateResult = 2;

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Redis returned an unexpected release result.');

		$this->lock->release($this->token());
	}

	public function test_it_rejects_an_unexpected_refresh_result(): void {
		$this->connection->evaluateResult = 2;

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Redis returned an unexpected refresh result.');

		$this->lock->refresh($this->token(), 60);
	}

	private function token(): LockToken {
		return new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);
	}
}
