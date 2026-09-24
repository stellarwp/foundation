<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * An application migration for the order relationship workflow.
 */
final class CascadeOrderReference extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$table = $schema->table($this->prefix . '_items');
		$table->dropForeignKey('order');
		$table->foreignKey('order', 'order_id')->references($this->prefix . '_orders', 'id')->cascadeOnDelete()->cascadeOnUpdate();
	}

	public function down(Blueprint $schema): void {
		$table = $schema->table($this->prefix . '_items');
		$table->dropForeignKey('order');
		$table->foreignKey('order', 'order_id')->references($this->prefix . '_orders', 'id');
	}
}
