<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Replace an existing column's complete definition, with an explicit inverse.
 */
final readonly class WidenEntryName implements Migration
{
	public const string ID = '20260922000220';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->comment('Imported entries')->string('name', 120)->nullable()->change();
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->table)->comment('Application entries')->string('name', 50)->change();
	}
}
