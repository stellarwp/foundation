<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

/**
 * Simulates a migration that fails to restore its original database scope.
 */
final readonly class ContextChangingMigration implements Migration
{
	public function __construct(
		private string $id,
		private TestDatabaseScope $scope,
		private int $nextScopeId
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function up(Schema $schema): void {
		$this->scope->currentId = $this->nextScopeId;
	}

	public function down(Schema $schema): void {
		$this->scope->currentId = $this->nextScopeId;
	}
}
