<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Remove a relationship without removing its automatically supplied index.
 */
final class RemoveOrderReference extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->dropForeignKey('order');
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->foreignKey('order', 'order_id')->references($this->prefix . '_orders', 'id')->cascadeOnDelete();
	}
}
