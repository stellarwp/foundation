<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * A change whose author refuses automatic reversal to protect data.
 */
final readonly class AddEntryArchivedFlag implements Migration
{
	public const string ID = '20260922000210';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->boolean('archived')->default(false);
	}

	public function down(Blueprint $schema): void {
		throw IrreversibleMigration::forMigration(self::ID);
	}
}
