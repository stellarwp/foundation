<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration\Factories;

use StellarWP\Foundation\Database\Migration\Factories\SessionFactory;
use StellarWP\Foundation\Database\Migration\Lease;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\SystemClock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseScope;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\TestCase;

final class SessionFactoryTest extends TestCase
{
	public function test_it_creates_sessions_bound_to_the_configured_schema(): void {
		$schema  = new RecordingSchema();
		$factory = new SessionFactory();
		$session = $factory->create($schema, $this->lease());

		$session->apply(new TestMigration('2026_09_01_000001_create_example'));

		$this->assertSame(['up:2026_09_01_000001_create_example'], $schema->statements);
	}

	public function test_it_creates_a_new_session_for_each_migration_operation(): void {
		$factory = new SessionFactory();
		$schema  = new RecordingSchema();
		$lease   = $this->lease();

		$this->assertNotSame($factory->create($schema, $lease), $factory->create($schema, $lease));
	}

	private function lease(): Lease {
		$lock  = new InMemoryLock(new SystemClock());
		$token = $lock->acquire('nx-foundation-database-migrations', 300);

		$this->assertNotNull($token);

		return new Lease($lock, new TestDatabaseScope(), 1, $token, 300);
	}
}
