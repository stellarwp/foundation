<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Lock;

use DateTimeImmutable;
use InvalidArgumentException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\TestCase;

final class LockTokenTest extends TestCase
{
	public function test_it_determines_whether_the_token_is_expired(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->assertFalse($token->isExpired(new DateTimeImmutable('2026-01-01 00:00:59')));
		$this->assertTrue($token->isExpired(new DateTimeImmutable('2026-01-01 00:01:00')));
	}

	public function test_it_matches_tokens_for_the_same_lock_owner(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$this->assertTrue($token->matches(new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:02:00')
		)));
		$this->assertFalse($token->matches(new LockToken(
			name: 'queue:sync',
			owner: 'other-owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		)));
		$this->assertFalse($token->matches(new LockToken(
			name: 'queue:cleanup',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		)));
	}

	public function test_it_refreshes_with_the_same_lock_name_and_owner(): void {
		$token = new LockToken(
			name: 'queue:sync',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);

		$refreshed = $token->refresh(new DateTimeImmutable('2026-01-01 00:02:00'));

		$this->assertSame($token->name, $refreshed->name);
		$this->assertSame($token->owner, $refreshed->owner);
		$this->assertSame('2026-01-01 00:02:00', $refreshed->expiresAt->format('Y-m-d H:i:s'));
	}

	public function test_it_rejects_an_empty_lock_name(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock name cannot be empty.');

		new LockToken(
			name: '',
			owner: 'owner',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);
	}

	public function test_it_rejects_an_empty_owner(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Lock owner cannot be empty.');

		new LockToken(
			name: 'queue:sync',
			owner: '',
			expiresAt: new DateTimeImmutable('2026-01-01 00:01:00')
		);
	}
}
