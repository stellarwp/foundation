<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * Drop a table an earlier migration created; a later migration recreates it differently.
 */
final readonly class DropEntryTags implements Migration
{
	public const string ID = '20260922000160';

	public function __construct(
		private TagsTable $table,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->drop($this->table);
	}

	public function down(Blueprint $schema): void {
		throw IrreversibleMigration::forMigration(self::ID);
	}
}
