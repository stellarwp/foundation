<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Migrations\Contracts\MigratesData;
use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\DataMigrationContext;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Data-only migration; the schema declarations are intentionally empty and the data step is repeat-safe.
 */
final readonly class BackfillEntryStatus implements MigratesData, Migration
{
	public const string ID = '20260922000300';

	public function __construct(
		private string $physicalName,
	) {
	}

	public function up(Blueprint $schema): void {
	}

	public function down(Blueprint $schema): void {
	}

	public function migrate(DataMigrationContext $context): void {
		$context->db->executeStatement("UPDATE `{$this->physicalName}` SET status = 'active' WHERE status = ''");
	}
}
