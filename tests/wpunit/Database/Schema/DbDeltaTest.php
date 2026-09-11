<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Database\Schema;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use phpmock\mockery\PHPMockery;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Schema\DbDelta;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class DbDeltaTest extends WPTestCase
{
	use MockeryPHPUnitIntegration;

	private const string SQL = 'CREATE TABLE wp_example (id bigint)';

	private string $originalLastError;

	protected function setUp(): void {
		parent::setUp();

		$this->originalLastError     = $GLOBALS['wpdb']->last_error;
		$GLOBALS['wpdb']->last_error = '';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']->last_error = $this->originalLastError;

		parent::tearDown();
	}

	public function test_it_executes_the_schema_definition_once(): void {
		$dbDelta = PHPMockery::mock('StellarWP\Foundation\Database\Schema', 'dbDelta');
		$dbDelta->with([self::SQL], true)->once()->andReturn([]);

		(new DbDelta())->execute(self::SQL);

		$this->addToAssertionCount(1);
	}

	public function test_it_translates_wordpress_database_errors(): void {
		$dbDelta = PHPMockery::mock('StellarWP\Foundation\Database\Schema', 'dbDelta');
		$dbDelta->with([self::SQL], true)->once()->andReturnUsing(static function (): array {
			$GLOBALS['wpdb']->last_error = 'Could not alter the table.';

			return [];
		});

		$this->expectException(QueryException::class);
		$this->expectExceptionMessage('Could not alter the table.');

		(new DbDelta())->execute(self::SQL);
	}

	public function test_it_fails_when_the_global_wordpress_database_is_unavailable(): void {
		$wpdb = $GLOBALS['wpdb'];
		unset($GLOBALS['wpdb']);

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('The global wpdb instance is not available.');

		try {
			(new DbDelta())->execute(self::SQL);
		} finally {
			$GLOBALS['wpdb'] = $wpdb;
		}
	}
}
