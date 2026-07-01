<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Lock;

use DateTimeImmutable;
use InvalidArgumentException;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;

final class InMemoryLockTest extends TestCase
{
	private MutableClock $clock;

	private InMemoryLock $lock;

	protected function setUp(): void {
		parent::setUp();

		$this->clock = new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00'));
		$this->lock  = new InMemoryLock($this->clock);
	}

	public function test_it_acquires_a_named_lock(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertSame('queue:sync', $token->name);
		$this->assertTrue($this->lock->isAcquired('queue:sync'));
	}

	public function test_it_refuses_to_acquire_a_lock_that_is_already_owned(): void {
		$first = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $first);
		$this->assertNull($this->lock->acquire('queue:sync', 60));
	}

	public function test_it_tracks_named_locks_independently(): void {
		$sync    = $this->lock->acquire('queue:sync', 60);
		$cleanup = $this->lock->acquire('queue:cleanup', 60);

		$this->assertInstanceOf(LockToken::class, $sync);
		$this->assertInstanceOf(LockToken::class, $cleanup);

		$this->assertTrue($this->lock->release($sync));
		$this->assertFalse($this->lock->isAcquired('queue:sync'));
		$this->assertTrue($this->lock->isAcquired('queue:cleanup'));
	}

	public function test_it_expires_named_locks_independently(): void {
		$sync    = $this->lock->acquire('queue:sync', 30);
		$cleanup = $this->lock->acquire('queue:cleanup', 90);

		$this->clock->advance(31);

		$this->assertInstanceOf(LockToken::class, $sync);
		$this->assertInstanceOf(LockToken::class, $cleanup);
		$this->assertFalse($this->lock->isAcquired('queue:sync'));
		$this->assertTrue($this->lock->isAcquired('queue:cleanup'));
	}

	public function test_it_allows_a_lock_to_be_acquired_after_it_expires(): void {
		$first = $this->lock->acquire('queue:sync', 60);

		$this->clock->advance(61);

		$second = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $first);
		$this->assertInstanceOf(LockToken::class, $second);
		$this->assertNotSame($first->owner, $second->owner);
	}

	public function test_it_treats_a_lock_as_expired_at_the_expiration_boundary(): void {
		$first = $this->lock->acquire('queue:sync', 60);

		$this->clock->advance(60);

		$second = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $first);
		$this->assertFalse($this->lock->release($first));
		$this->assertInstanceOf(LockToken::class, $second);
		$this->assertNotSame($first->owner, $second->owner);
	}

	public function test_it_releases_a_lock_with_the_matching_token(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertTrue($this->lock->release($token));
		$this->assertFalse($this->lock->isAcquired('queue:sync'));
	}

	public function test_it_refuses_to_release_a_lock_with_a_different_token(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertFalse($this->lock->release(new LockToken(
			name: 'queue:sync',
			owner: 'other-owner',
			expiresAt: $token->expiresAt
		)));
		$this->assertTrue($this->lock->isAcquired('queue:sync'));
	}

	public function test_it_does_not_release_an_expired_lock(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->clock->advance(61);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertFalse($this->lock->release($token));
		$this->assertFalse($this->lock->isAcquired('queue:sync'));
	}

	public function test_it_refreshes_a_lock_with_the_matching_token(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->clock->advance(30);

		$this->assertInstanceOf(LockToken::class, $token);

		$refreshed = $this->lock->refresh($token, 120);

		$this->assertInstanceOf(LockToken::class, $refreshed);
		$this->assertSame($token->name, $refreshed->name);
		$this->assertSame($token->owner, $refreshed->owner);
		$this->assertSame('2026-01-01 00:02:30', $refreshed->expiresAt->format('Y-m-d H:i:s'));

		$this->clock->advance(31);

		$this->assertTrue($this->lock->isAcquired('queue:sync'));

		$this->clock->advance(89);

		$this->assertFalse($this->lock->isAcquired('queue:sync'));
	}

	public function test_it_refuses_to_refresh_a_lock_with_a_different_token(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertNull($this->lock->refresh(new LockToken(
			name: 'queue:sync',
			owner: 'other-owner',
			expiresAt: $token->expiresAt
		), 120));
	}

	public function test_it_refuses_to_refresh_an_expired_lock(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->clock->advance(61);

		$this->assertInstanceOf(LockToken::class, $token);
		$this->assertNull($this->lock->refresh($token, 120));
		$this->assertFalse($this->lock->isAcquired('queue:sync'));
	}

	public function test_it_rejects_an_empty_lock_name(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock name cannot be empty.');

		$this->lock->acquire('', 60);
	}

	public function test_it_rejects_an_invalid_ttl(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock TTL must be greater than zero seconds.');

		$this->lock->acquire('queue:sync', 0);
	}

	public function test_it_rejects_an_invalid_ttl_when_the_lock_is_already_owned(): void {
		$this->lock->acquire('queue:sync', 60);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock TTL must be greater than zero seconds.');

		$this->lock->acquire('queue:sync', 0);
	}

	public function test_it_rejects_an_invalid_ttl_when_refreshing_a_lock(): void {
		$token = $this->lock->acquire('queue:sync', 60);

		$this->assertInstanceOf(LockToken::class, $token);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock TTL must be greater than zero seconds.');

		$this->lock->refresh($token, 0);
	}
}
