<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * Explicitly replace an index with a unique one of the same name.
 */
final readonly class ReplaceNoteLookup implements Migration
{
	public const string ID = '20260922000207';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function id(): string {
		return self::ID;
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->dropIndex('note_lookup')->unique('note_lookup', 'note');
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->table)->dropIndex('note_lookup')->index('note_lookup', 'note');
	}
}
