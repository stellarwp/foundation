<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Connection;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;
use StellarWP\Foundation\Migrations\Schema\ValueObjects\Rename;

/**
 * Plan explicit renames and align snapshots before comparing ordinary schema changes.
 *
 * @internal
 */
final readonly class RenamePlanner
{
	/**
	 * Receive the shared connection for platform quoting and table rename SQL.
	 */
	public function __construct(
		private Connection $db,
		private ColumnRename $columns,
	) {
	}

	/**
	 * Emit each declared rename and align the comparison snapshot with its new names.
	 *
	 * @return list<string>
	 */
	public function plan(SchemaState $before, SchemaState $after): array {
		$sql = [];

		foreach ($after->renames as $rename) {
			$sql = array_merge($sql, $this->sql($rename, $before));
			$before->rename($rename);
		}

		return $sql;
	}

	/**
	 * Keep each platform statement separate and in execution order.
	 *
	 * @return list<string>
	 */
	private function sql(Rename $rename, SchemaState $actual): array {
		$platform = $this->db->getDatabasePlatform();
		$from     = $platform->quoteSingleIdentifier($rename->from);
		$to       = $platform->quoteSingleIdentifier($rename->to);

		if ($rename->table === null) {
			return [
				$platform->getRenameTableSQL($from, $to),
			];
		}

		return $this->columns->sql($actual->schema->getTable($rename->table), $rename->from, $rename->to);
	}
}
