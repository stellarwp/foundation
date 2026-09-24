<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * An application migration for the order relationship workflow.
 */
final class CreateItems extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$table = $schema->create($this->prefix . '_items');
		$table->bigIncrements();
		$table->unsignedBigInteger('order_id')->nullable();
		$table->unsignedBigInteger('account_id')->nullable();
		$table->index('order_lookup', 'order_id');
		$table->index('account_order_lookup', 'account_id', 'order_id');
	}

	public function down(Blueprint $schema): void {
		$schema->drop($this->prefix . '_items');
	}
}
