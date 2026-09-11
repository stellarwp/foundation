<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

final readonly class TestMigration implements Migration
{
	public function __construct(
		private string $id
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function up(Schema $schema): void {
		$schema->execute('up:' . $this->id);
	}

	public function down(Schema $schema): void {
		$schema->execute('down:' . $this->id);
	}
}
