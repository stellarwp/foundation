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

	public function test_it_executes_and_verifies_the_schema_definition(): void {
		$dbDelta = PHPMockery::mock('StellarWP\Foundation\Database\Schema', 'dbDelta');
		$dbDelta->with([self::SQL], true)->once()->andReturn([]);
		$dbDelta->with([self::SQL], false)->once()->andReturn([]);

		(new DbDelta())->execute(self::SQL);

		$this->addToAssertionCount(1);
	}

	public function test_it_fails_when_schema_changes_remain_pending(): void {
		$dbDelta = PHPMockery::mock('StellarWP\Foundation\Database\Schema', 'dbDelta');
		$dbDelta->with([self::SQL], true)->once()->andReturn([]);
		$dbDelta->with([self::SQL], false)->once()->andReturn([
			'wp_example.name' => 'Added column wp_example.name',
		]);

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('Database schema reconciliation did not complete: Added column wp_example.name');

		(new DbDelta())->execute(self::SQL);
	}

	public function test_it_ignores_wordpress_62_created_table_dry_run_false_positives(): void {
		$table = $GLOBALS['wpdb']->prefix . 'foundation_dbdelta_existing';
		$sql   = sprintf('CREATE TABLE `%s` (id bigint)', $table);

		$GLOBALS['wpdb']->query(sprintf('CREATE TABLE `%s` (id bigint)', $table));

		$dbDelta = PHPMockery::mock('StellarWP\Foundation\Database\Schema', 'dbDelta');
		$dbDelta->with([$sql], true)->once()->andReturn([]);
		$dbDelta->with([$sql], false)->once()->andReturn([
			'`' . $table . '`' => 'Created table `' . $table . '`',
		]);

		try {
			(new DbDelta())->execute($sql);
			$this->addToAssertionCount(1);
		} finally {
			$GLOBALS['wpdb']->query(sprintf('DROP TABLE IF EXISTS `%s`', $table));
		}
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
