<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration\ValueObjects;

use DateTimeImmutable;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;
use StellarWP\Foundation\Database\Migration\ValueObjects\Status;
use StellarWP\Foundation\Tests\TestCase;

final class StatusTest extends TestCase
{
	public function test_it_describes_a_pending_migration(): void {
		$status = Status::pending('2026_01_01_000001_create_users');

		$this->assertSame('2026_01_01_000001_create_users', $status->migration);
		$this->assertSame('pending', $status->state());
		$this->assertTrue($status->isPending());
		$this->assertFalse($status->isApplied());
		$this->assertFalse($status->isUnavailable());
		$this->assertNull($status->batch);
		$this->assertNull($status->ranAt);
	}

	public function test_it_describes_a_recorded_migration(): void {
		$record = $this->record();

		$status = Status::applied($record);

		$this->assertSame($record->migration, $status->migration);
		$this->assertSame('applied', $status->state());
		$this->assertFalse($status->isPending());
		$this->assertTrue($status->isApplied());
		$this->assertFalse($status->isUnavailable());
		$this->assertSame($record->batch, $status->batch);
		$this->assertSame($record->ranAt, $status->ranAt);
	}

	public function test_it_describes_an_unavailable_recorded_migration(): void {
		$record = $this->record();

		$status = Status::unavailable($record);

		$this->assertSame($record->migration, $status->migration);
		$this->assertSame('unavailable', $status->state());
		$this->assertFalse($status->isPending());
		$this->assertFalse($status->isApplied());
		$this->assertTrue($status->isUnavailable());
		$this->assertSame($record->batch, $status->batch);
		$this->assertSame($record->ranAt, $status->ranAt);
	}

	private function record(): Record {
		return new Record(
			id: 1,
			migration: '2026_01_01_000001_create_users',
			batch: 2,
			ranAt: new DateTimeImmutable('2026-01-01 00:00:00')
		);
	}
}
