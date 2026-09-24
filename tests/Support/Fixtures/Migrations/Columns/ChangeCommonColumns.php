<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Migrations\Columns;

use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Change each common type's attributes and supply its complete inverse.
 */
final class ChangeCommonColumns extends Migration
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
		$table = $schema->table($this->table);
		$table->json('metadata')->comment('Required metadata')->change();
		$table->date('starts_on')->nullable()->change();
		$table->time('opens_at')->nullable()->default('10:00:00')->change();
		$table->smallInteger('attempts')->default(3)->change();
		$table->char('currency', 4)->default('USDX')->comment('Extended currency')->change();
		$table->mediumText('content')->comment('Required content')->change();
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(Blueprint $schema): void {
		$table = $schema->table($this->table);
		$table->json('metadata')->nullable()->comment('External metadata')->change();
		$table->date('starts_on')->default('2026-01-01')->change();
		$table->time('opens_at')->default('09:00:00')->change();
		$table->smallInteger('attempts')->unsigned()->default(0)->change();
		$table->char('currency', 3)->default('USD')->comment('Currency code')->change();
		$table->mediumText('content')->nullable()->comment('Article content')->change();
	}
}
