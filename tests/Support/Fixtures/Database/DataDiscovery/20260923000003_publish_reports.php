<?php declare(strict_types=1);

use StellarWP\Foundation\Migrations\Contracts\DescribesMigration;
use StellarWP\Foundation\Migrations\Contracts\MigratesData;
use StellarWP\Foundation\Migrations\DataMigrationContext;
use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

return new class extends Migration implements DescribesMigration, MigratesData {
	public function up(Blueprint $schema): void {
	}

	public function migrate(DataMigrationContext $context): void {
		$table = $context->quotedTable('discovery_reports');
		$context->db->executeStatement('UPDATE ' . $table . ' SET title = ? WHERE title = ?', ['Published report', 'Existing report']);
	}

	public function describe(): string {
		return 'Publish existing reports';
	}
};
