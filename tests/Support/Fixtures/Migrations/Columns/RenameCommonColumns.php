<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\Columns;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Retain new column types and their attributes through native and legacy renames.
 */
final class RenameCommonColumns extends Migration
{
	private const array COLUMNS = [
		'metadata',
		'starts_on',
		'opens_at',
		'attempts',
		'currency',
		'content',
	];

	/**
	 * Receive the application's stable table name.
	 */
	public function __construct(
		private readonly string $table,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(Blueprint $schema): void {
		$table = $schema->table($this->table);

		foreach (self::COLUMNS as $name) {
			$table->renameColumn($name, $name . '_renamed');
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(Blueprint $schema): void {
		$table = $schema->table($this->table);

		foreach (array_reverse(self::COLUMNS) as $name) {
			$table->renameColumn($name . '_renamed', $name);
		}
	}
}
