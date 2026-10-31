<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * An application migration for the order relationship workflow.
 */
final class CreateOrders extends Migration
{
	public function __construct(
		private readonly string $prefix,
	) {
	}

	public function up(Blueprint $schema): void {
		$table = $schema->create($this->prefix . '_orders');
		$table->bigIncrements();
		$table->unsignedBigInteger('account_id');
		$table->unique('account_order', 'account_id', 'id');
	}

	public function down(Blueprint $schema): void {
		$schema->drop($this->prefix . '_orders');
	}
}
