<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Migration\Contracts\DescribesMigration;
use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * Alteration exactly as a generated alter migration would declare it: no guards.
 */
final readonly class AddEntryNote implements DescribesMigration, Migration
{
	public const string ID = '20260922000200';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function describe(): string {
		return 'Add an optional note to entries';
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->string('note', 100)->nullable();
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->table)->dropColumn('note');
	}
}
