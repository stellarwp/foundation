<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

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

	public function id(): string {
		return self::ID;
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->boolean('archived')->default(false);
	}

	public function down(Blueprint $schema): void {
		throw IrreversibleMigration::forMigration(self::ID);
	}
}
