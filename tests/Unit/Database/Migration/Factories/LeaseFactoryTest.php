<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration\Factories;

use DateTimeImmutable;
use StellarWP\Foundation\Database\Migration\Factories\LeaseFactory;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\SystemClock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseScope;
use StellarWP\Foundation\Tests\TestCase;

final class LeaseFactoryTest extends TestCase
{
	public function test_it_creates_a_new_lease_for_each_acquired_lock(): void {
		$factory = new LeaseFactory();
		$lock    = new InMemoryLock(new SystemClock());
		$scope   = new TestDatabaseScope();
		$token   = new LockToken(
			'nx-foundation-database-migrations',
			'owner',
			new DateTimeImmutable('+5 minutes')
		);

		$this->assertNotSame(
			$factory->create($lock, $scope, 1, $token, 300),
			$factory->create($lock, $scope, 1, $token, 300)
		);
	}
}
