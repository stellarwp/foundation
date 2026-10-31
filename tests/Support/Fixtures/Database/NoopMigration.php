<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

final readonly class NoopMigration implements Migration
{
	public function __construct(
		private string $id
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function up(Schema $schema): void {
	}

	public function down(Schema $schema): void {
	}
}
