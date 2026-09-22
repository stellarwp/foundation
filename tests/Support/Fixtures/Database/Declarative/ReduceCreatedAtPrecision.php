<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * Change precision and default together: Doctrine sees the default change, Foundation the precision.
 */
final readonly class ReduceCreatedAtPrecision implements Migration
{
	public const string ID = '20260922000240';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function id(): string {
		return self::ID;
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->table)->dateTime('created_at', 3)->useCurrent()->useCurrentOnUpdate()->change();
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->table)->dateTime('created_at', 6)->useCurrent()->useCurrentOnUpdate()->change();
	}
}
