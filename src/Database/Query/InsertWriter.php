<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Query\Upsert\Contracts\Builder;
use StellarWP\Foundation\Database\Query\ValueObjects\Fragment;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;

/**
 * Validates whole imports and executes writes within the server's parameter limit.
 *
 * @internal
 */
final readonly class InsertWriter
{
	/**
	 * Configure the statement parameter budget, at most MySQL's 65,535 placeholders.
	 *
	 * @throws InvalidArgumentException For an invalid parameter budget.
	 */
	public function __construct(
		private Executor $executor,
		private Builder $builder,
		private IdentifierQuoter $quoter,
		private int $parameterLimit = 65535,
	) {
		if ($parameterLimit < 1 || $parameterLimit > 65535) {
			throw new InvalidArgumentException('The parameter limit must be between 1 and 65535.');
		}
	}

	/**
	 * Insert one row or many, optionally updating named columns on a key conflict.
	 *
	 * Every row is validated before any write. Chunked writes require a caller-owned
	 * transaction for atomicity across statements. No transaction is opened here.
	 * The total is an integer: an insert or upsert affects at most two rows per
	 * supplied row, so an in-memory input cannot exhaust the integer range.
	 *
	 * @param array<string, mixed>|list<array<string, mixed>> $rows
	 * @param list<string>                                    $update
	 *
	 * @throws InvalidArgumentException For invalid columns, values, or mismatched rows.
	 * @throws Exception                When execution fails, including in a later chunk.
	 */
	public function insert(TableReference $table, array $rows, array $update = []): int {
		if ($table->alias !== null) {
			throw new InvalidArgumentException('Insert targets cannot have an alias.');
		}

		if ($rows === []) {
			return 0;
		}

		$rows = array_is_list($rows) ? $rows : [
			$rows,
		];
		$normalized = $this->normalizeRows($rows);
		$columns    = array_keys($normalized[0]);

		if (count($columns) > $this->parameterLimit) {
			throw new InvalidArgumentException('One insert row exceeds the statement parameter limit.');
		}

		foreach ($update as $column) {
			if (! in_array($column, $columns, true)) {
				throw new InvalidArgumentException('Upsert update columns must occur in the inserted rows.');
			}
		}

		$suffix      = $update === [] ? '' : $this->builder->build($update, $table->name);
		$prefix      = 'INSERT INTO ' . $this->quoter->quote($table->name) . ' (' . implode(', ', array_map($this->quoter->quote(...), $columns)) . ') VALUES ';
		$placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
		$chunkSize   = intdiv($this->parameterLimit, count($columns));
		$total       = 0;

		for ($offset = 0, $rowCount = count($normalized); $offset < $rowCount; $offset += $chunkSize) {
			$chunk    = array_slice($normalized, $offset, $chunkSize);
			$bindings = [];

			foreach ($chunk as $row) {
				array_push($bindings, ...array_values($row));
			}

			$sql = $prefix . implode(', ', array_fill(0, count($chunk), $placeholder)) . $suffix;
			$total += (int) $this->executor->statement(new Fragment($sql, $bindings));
		}

		return $total;
	}

	/**
	 * Insert one row, including a defaults-only row, before reading its generated ID.
	 *
	 * @param array<array-key, mixed> $data Unvalidated input that must describe exactly one row.
	 *
	 * @throws InvalidArgumentException For aliased targets, row lists, or invalid columns and values.
	 * @throws Exception                When insertion fails or no generated identifier is available.
	 */
	public function insertGetId(TableReference $table, array $data): int|string {
		if ($table->alias !== null) {
			throw new InvalidArgumentException('Insert targets cannot have an alias.');
		}

		if ($data !== [] && array_is_list($data)) {
			throw new InvalidArgumentException('insertGetId() requires one column-to-value row, not a list of rows.');
		}

		if ($data === []) {
			$this->executor->statement(new Fragment('INSERT INTO ' . $this->quoter->quote($table->name) . ' () VALUES ()'));
		} else {
			$this->insert($table, $data);
		}

		return $this->executor->lastInsertId();
	}

	/**
	 * Validate all rows and normalize their values into a consistent column order.
	 *
	 * @param non-empty-list<mixed> $rows
	 *
	 * @return non-empty-list<non-empty-array<string, int|string|null>>
	 */
	private function normalizeRows(array $rows): array {
		$normalized = [];
		$columns    = null;

		foreach ($rows as $row) {
			if (! is_array($row) || $row === []) {
				throw new InvalidArgumentException('Every insert row must be a nonempty column-to-value array.');
			}

			if ($columns === null) {
				$columns = array_keys($row);

				foreach ($columns as $column) {
					if (! is_string($column)) {
						throw new InvalidArgumentException('Insert columns must be unqualified identifiers.');
					}

					$this->quoter->quote($column);
				}
			}

			if (count($row) !== count($columns) || array_diff($columns, array_keys($row)) !== []) {
				throw new InvalidArgumentException('Every insert row must have the same column set.');
			}

			$values = [];

			foreach ($columns as $column) {
				$values[$column] = $this->executor->normalize($row[$column]);
			}

			$normalized[] = $values;
		}

		return $normalized;
	}
}
