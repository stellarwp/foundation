<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Database\Table\Blueprint;

/**
 * Adds an item status and replaces the lookup index in one retryable alteration.
 */
final readonly class AddRecoveryStatusMigration implements Migration
{
	/**
	 * Receive the application table whose schema this migration extends.
	 */
	public function __construct(private RecoveryTable $table) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return '2026_09_07_000001_add_recovery_status';
	}

	/**
	 * Add status filtering while retaining existing item data.
	 */
	public function up(Schema $schema): void {
		$blueprint = Blueprint::for($this->table);
		$blueprint->string('status', 20)->default('pending');
		$blueprint->dropIndex('lookup');
		$blueprint->index('lookup', 'status', 'name');

		$schema->alter($blueprint);
	}

	/**
	 * Prevent removal of application status data without an explicit recovery plan.
	 *
	 * @throws IrreversibleMigration Always, because this fixture only defines forward recovery.
	 */
	public function down(Schema $schema): void {
		throw new IrreversibleMigration($this->id());
	}
}
