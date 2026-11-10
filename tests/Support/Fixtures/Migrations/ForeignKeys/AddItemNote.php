<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Add an unrelated column while preserving existing relationships.
 */
final class AddItemNote extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->string('note')->nullable();
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->dropColumn('note');
	}
}
