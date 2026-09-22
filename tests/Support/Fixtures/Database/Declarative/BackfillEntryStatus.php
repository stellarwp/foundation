<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use Doctrine\DBAL\Connection;
use StellarWP\Foundation\Database\Migration\Contracts\MigratesData;
use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

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

	public function id(): string {
		return self::ID;
	}

	public function up(Blueprint $schema): void {
	}

	public function down(Blueprint $schema): void {
	}

	public function migrate(Connection $connection): void {
		$connection->executeStatement("UPDATE `{$this->physicalName}` SET status = 'active' WHERE status = ''");
	}
}
