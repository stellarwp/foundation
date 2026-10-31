<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration\ValueObjects;

use StellarWP\Foundation\Database\Migration\ValueObjects\Result;
use StellarWP\Foundation\Tests\TestCase;

final class ResultTest extends TestCase
{
	public function test_it_counts_ran_and_rolled_back_migrations(): void {
		$result = new Result(
			ran: ['2026_01_01_000001_create_users'],
			rolledBack: ['2026_01_01_000002_create_posts'],
			skipped: ['2026_01_01_000003_create_comments']
		);

		$this->assertSame(2, $result->count());
	}
}
