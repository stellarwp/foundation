<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * An application migration for the order relationship workflow.
 */
final class AddCompositeReference extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->foreignKey('account_order', 'account_id', 'order_id')->references($this->prefix . '_orders', 'account_id', 'id')->nullOnDelete()->nullOnUpdate();
	}

	public function down(Blueprint $schema): void {
		$schema->table($this->prefix . '_items')->dropForeignKey('account_order');
	}
}
