<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Exceptions;

use RuntimeException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Tests\TestCase;

final class QueryExceptionTest extends TestCase
{
	public function test_it_exposes_query_context(): void {
		$previous  = new RuntimeException('Previous failure.');
		$exception = new QueryException(
			'Query failed.',
			'SELECT * FROM %i WHERE id = %d',
			['foundation_table', 23],
			'Table does not exist.',
			$previous
		);

		$this->assertSame('Query failed.', $exception->getMessage());
		$this->assertSame('SELECT * FROM %i WHERE id = %d', $exception->sql());
		$this->assertSame(['foundation_table', 23], $exception->bindings());
		$this->assertSame('Table does not exist.', $exception->databaseError());
		$this->assertSame($previous, $exception->getPrevious());
	}

	public function test_it_allows_missing_bindings_and_database_error(): void {
		$exception = new QueryException('Query failed.', 'SELECT 1');

		$this->assertSame([], $exception->bindings());
		$this->assertNull($exception->databaseError());
	}
}
