<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * An application migration for the order relationship workflow.
 */
final class CreateReferencedItems extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$table = $schema->create($this->prefix . '_items');
		$table->bigIncrements();
		$table->unsignedBigInteger('order_id');
		$table->foreignKey('order', 'order_id')->references($this->prefix . '_orders', 'id')->cascadeOnDelete();
	}

	public function down(Blueprint $schema): void {
		$schema->drop($this->prefix . '_items');
	}
}
