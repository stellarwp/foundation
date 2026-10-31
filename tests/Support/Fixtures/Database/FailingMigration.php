<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use RuntimeException;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

final readonly class FailingMigration implements Migration
{
	public function __construct(
		private string $id,
		private bool $failUp = false,
		private bool $failDown = false
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function up(Schema $schema): void {
		if ($this->failUp) {
			throw new RuntimeException('Migration up failed.');
		}

		$schema->execute('up:' . $this->id);
	}

	public function down(Schema $schema): void {
		if ($this->failDown) {
			throw new RuntimeException('Migration down failed.');
		}

		$schema->execute('down:' . $this->id);
	}
}
