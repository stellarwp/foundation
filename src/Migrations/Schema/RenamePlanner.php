<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use StellarWP\Foundation\Migrations\Exceptions\IncompatibleSchema;
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
	 * Recognize completed renames, then align historical and live names with the target.
	 *
	 * Both supplied snapshots are private to this planning operation. No SQL is executed.
	 *
	 * @throws IncompatibleSchema       When live names cannot identify a completed prefix.
	 * @throws InvalidArgumentException When a declaration reuses or removes renamed names ambiguously.
	 *
	 * @return list<string>
	 */
	public function plan(SchemaState $before, SchemaState $actual, SchemaState $after, string $id): array {
		if ($after->renames === []) {
			return [];
		}

		if (! $this->isRecoverable($before, $after)) {
			throw new InvalidArgumentException('Migration ' . $id . ' has ambiguous renames; put name reuse or removal in a later migration.');
		}

		$completed = $this->completedPrefix($actual, $after->renames);

		if ($completed === null) {
			throw new IncompatibleSchema('Migration ' . $id . ' cannot resume its renames: a source is missing, a destination conflicts, or names changed out of order.');
		}

		foreach (array_reverse(array_slice($after->renames, 0, $completed)) as $rename) {
			$actual->rename($rename->inverse());
		}

		$sql = [];

		foreach ($after->renames as $position => $rename) {
			if ($position >= $completed) {
				$sql = array_merge($sql, $this->sql($rename, $actual));
			}

			$before->rename($rename);
			$actual->rename($rename);
		}

		return $sql;
	}

	/**
	 * Require every interruption point to have a distinguishable set of names.
	 */
	private function isRecoverable(SchemaState $before, SchemaState $after): bool {
		$probe = clone $before;

		foreach ($after->renames as $position => $rename) {
			if (! $this->applySequence($probe, [
				$rename,
			]) || $this->completedPrefix($probe, $after->renames) !== $position + 1) {
				return false;
			}
		}

		return $this->completedPrefix($after, $after->renames) === count($after->renames);
	}

	/**
	 * Find how much of the ordered rename SQL already completed before an interruption.
	 *
	 * Rewind each possible prefix on a private snapshot, then check the complete sequence.
	 * This also handles a table rename followed by column renames, and chained names.
	 *
	 * @param list<Rename> $renames
	 */
	private function completedPrefix(SchemaState $actual, array $renames): ?int {
		for ($completed = 0; $completed <= count($renames); $completed++) {
			$probe  = clone $actual;
			$rewind = array_map(static fn (Rename $rename): Rename => $rename->inverse(), array_reverse(array_slice($renames, 0, $completed)));

			if ($this->applySequence($probe, array_merge($rewind, $renames))) {
				return $completed;
			}
		}

		return null;
	}

	/**
	 * Apply an unambiguous sequence to a private snapshot.
	 *
	 * @param list<Rename> $renames
	 */
	private function applySequence(SchemaState $state, array $renames): bool {
		foreach ($renames as $rename) {
			if (! $this->exists($state, $rename, $rename->from) || $this->exists($state, $rename, $rename->to)) {
				return false;
			}

			$state->rename($rename);
		}

		return true;
	}

	private function exists(SchemaState $state, Rename $rename, string $name): bool {
		if ($rename->table === null) {
			return $state->schema->hasTable($name);
		}

		return $state->schema->hasTable($rename->table)
			&& $state->schema->getTable($rename->table)->hasColumn($name);
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
