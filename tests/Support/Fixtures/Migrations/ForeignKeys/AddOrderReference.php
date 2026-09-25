<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * An application migration for the order relationship workflow.
 */
final class AddOrderReference extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->foreignKey('order', 'order_id')->references($this->prefix . '_orders', 'id');
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->dropForeignKey('order');
	}
}
