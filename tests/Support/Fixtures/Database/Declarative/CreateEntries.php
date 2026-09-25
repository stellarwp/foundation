<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Initial table definition exactly as a generated create migration would declare it: no guards.
 */
final readonly class CreateEntries implements Migration
{
	public const string ID = '20260922000100';

	public function __construct(
		private EntriesTable $table,
	) {
	}

	public function up(Blueprint $schema): void {
		$entries = $schema->create($this->table)->comment('Application entries');

		$entries->bigIncrements('id');
		$entries->string('name', 50);
		$entries->string('status', 20)->default('active')->comment('Current processing state');
		$entries->decimal('amount', 12, 4)->default('0');
		$entries->binary('token', 16)->nullable();
		$entries->dateTime('created_at', 6)->useCurrent();
		$entries->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

		$entries->index('status', 'status');
	}

	public function down(Blueprint $schema): void {
		$schema->drop($this->table);
	}
}
