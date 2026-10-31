<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Add a plain index; an existing index with the same name must be treated as a conflict.
 */
final readonly class IndexEntryNote implements Migration
{
	public const string ID = '20260922000205';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->index('note_lookup', 'note');
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->table)->dropIndex('note_lookup');
	}
}
