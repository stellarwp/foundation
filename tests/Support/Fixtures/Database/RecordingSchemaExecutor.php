<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Schema\Contracts\SchemaExecutor;

final class RecordingSchemaExecutor implements SchemaExecutor
{
	/**
	 * @var list<string>
	 */
	public array $statements = [];

	public function execute(string $sql): void {
		$this->statements[] = $sql;
	}
}
