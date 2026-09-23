<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Migration\Contracts\DescribesMigration;
use StellarWP\Foundation\Database\Migration\Contracts\MigratesData;
use StellarWP\Foundation\Database\Migration\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

return new class extends Migration implements DescribesMigration, MigratesData {
	public function up(Blueprint $schema): void {
	}

	public function migrate(Connection $db, TableNameResolver $names): void {
		$table = $db->getDatabasePlatform()->quoteSingleIdentifier($names->tableName('discovery_reports'));
		$db->executeStatement('UPDATE ' . $table . ' SET title = ? WHERE title = ?', ['Published report', 'Existing report']);
	}

	public function describe(): string {
		return 'Publish existing reports';
	}
};
