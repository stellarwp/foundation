<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Change only an attribute DBAL cannot compare: add ON UPDATE to an existing timestamp.
 */
final readonly class TrackEntryUpdates implements Migration
{
	public const string ID = '20260922000230';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->dateTime('created_at', 6)->useCurrent()->useCurrentOnUpdate()->change();
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->table)->dateTime('created_at', 6)->useCurrent()->change();
	}
}
