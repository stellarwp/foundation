<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\Columns;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Declare application columns with representative defaults and modifiers.
 */
final class CreateCommonColumns extends Migration
{
	/**
	 * Receive the application's stable table name.
	 */
	public function __construct(
		private readonly string $table,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(Blueprint $schema): void {
		$table = $schema->create($this->table);
		$table->bigIncrements();
		$table->json('metadata')->nullable()->comment('External metadata');
		$table->date('starts_on')->default('2026-01-01');
		$table->time('opens_at')->default('09:00:00');
		$table->smallInteger('attempts')->unsigned()->default(0);
		$table->char('currency', 3)->default('USD')->comment('Currency code');
		$table->mediumText('content')->nullable()->comment('Article content');
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(Blueprint $schema): void {
		$schema->drop($this->table);
	}
}
