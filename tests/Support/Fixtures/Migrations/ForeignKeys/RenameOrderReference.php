<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * An application migration for the order relationship workflow.
 */
final class RenameOrderReference extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$table = $schema->table($this->prefix . '_items');
		$table->dropForeignKey('order');
		$table->foreignKey('purchase', 'order_id')->references($this->prefix . '_orders', 'id');
	}

	public function down(Blueprint $schema): void {
		$table = $schema->table($this->prefix . '_items');
		$table->dropForeignKey('purchase');
		$table->foreignKey('order', 'order_id')->references($this->prefix . '_orders', 'id');
	}
}
