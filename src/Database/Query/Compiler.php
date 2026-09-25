<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Query\ValueObjects\Fragment;
use StellarWP\Foundation\Database\Query\ValueObjects\QueryState;
use StellarWP\Foundation\Database\Query\ValueObjects\Selection;

/**
 * Compile query snapshots without executing or modifying them.
 *
 * @internal
 */
final readonly class Compiler
{
	/**
	 * Receive quoting and table rendering without consulting the database.
	 */
	public function __construct(
		private IdentifierQuoter $quoter,
		private TableReferenceResolver $resolver,
	) {
	}

	/**
	 * Compile a read, optionally replacing its projection or limiting its result.
	 *
	 * @param list<Selection>|null $selection
	 */
	public function select(QueryState $state, ?array $selection = null, ?int $maximum = null, bool $ordered = true): Fragment {
		$selected = $selection ?? ($state->selection === [] ? [
			new Selection(new Fragment('*'), '*', true),
		] : $state->selection);
		$bindings = [];
		$columns  = [];

		foreach ($selected as $column) {
			$columns[] = $column->fragment->sql;
			array_push($bindings, ...$column->fragment->bindings);
		}

		$sql = 'SELECT ' . ($state->distinct ? 'DISTINCT ' : '') . implode(', ', $columns)
			. "\nFROM " . $this->resolver->sql($state->table);

		foreach ($state->joins as $join) {
			$sql .= "\n" . $join->sql;
			array_push($bindings, ...$join->bindings);
		}

		if ($state->where->sql !== '') {
			$sql .= "\nWHERE " . $state->where->sql;
			array_push($bindings, ...$state->where->bindings);
		}

		if ($state->groups !== []) {
			$sql .= "\nGROUP BY " . implode(', ', $state->groups);
		}

		if ($state->having->sql !== '') {
			$sql .= "\nHAVING " . $state->having->sql;
			array_push($bindings, ...$state->having->bindings);
		}

		$limit = $maximum === null ? $state->limit : min($state->limit ?? $maximum, $maximum);
		$sql .= $this->tail($ordered ? $state->orders : [], $limit, $state->offset);

		return new Fragment($sql, $bindings);
	}

	/**
	 * Aggregate the query's result rows while preserving shaped projections and aliases.
	 *
	 * @throws InvalidArgumentException When a column is invalid, absent from an explicit projection, or ambiguous in a shaped join.
	 */
	public function aggregate(QueryState $state, string $function, string $column): Fragment {
		$quoted = $column === '*' ? '*' : $this->quoter->column($column);

		if (! $this->shaped($state)) {
			return $this->select($state, [
				new Selection(new Fragment($function . '(' . $quoted . ')')),
			], ordered: false);
		}

		$selection = $state->selection;

		if ($selection === [] && ! $state->distinct && $state->groups === [] && $state->having->sql === '') {
			$selection = [
				new Selection(new Fragment($column === '*' ? '1' : $quoted)),
			];
		}

		if ($state->joins !== [] && ($selection === [] || in_array(true, array_column($selection, 'wildcard'), true))) {
			throw new InvalidArgumentException('Select distinct output columns before aggregating a shaped join.');
		}

		$parts         = explode('.', $column);
		$name          = $parts[count($parts) - 1];
		$outputColumns = array_column($state->selection, 'name');

		if (
			$column !== '*'
			&& ! in_array(null, $outputColumns, true)
			&& $outputColumns !== []
			&& ! in_array('*', $outputColumns, true)
			&& ! in_array(strtolower($name), $outputColumns, true)
		) {
			throw new InvalidArgumentException('Aggregate column "' . $name . '" is not selected. Include it in select() or use its selected alias.');
		}

		$inner  = $this->select($state, $selection === [] ? null : $selection);
		$output = $column === '*' ? '*' : $this->quoter->quote($name);

		return new Fragment(
			'SELECT ' . $function . '(' . $output . ")\nFROM (\n" . $inner->sql . "\n) AS `foundation_rows`",
			$inner->bindings,
		);
	}

	/**
	 * Preserve shaped selections because HAVING may depend on their aliases.
	 */
	public function exists(QueryState $state): Fragment {
		// MySQL 5.7 ignores LIMIT 0 inside EXISTS, and no rows can match it anyway.
		if ($state->limit === 0) {
			return new Fragment('SELECT 0');
		}

		$inner = $this->select($state, $this->shaped($state) ? null : [
			new Selection(new Fragment('1')),
		], ordered: $this->shaped($state));

		return new Fragment("SELECT EXISTS(\n" . $inner->sql . "\n)", $inner->bindings);
	}

	/**
	 * Compile a filtered, single-table update.
	 *
	 * @param array<string, mixed> $values
	 *
	 * @throws InvalidArgumentException When values are empty or the query cannot be used for an update.
	 */
	public function update(QueryState $state, array $values): Fragment {
		$this->writable($state);

		if ($values === []) {
			throw new InvalidArgumentException('An update requires at least one column value.');
		}

		$columns = [];

		foreach (array_keys($values) as $column) {
			$columns[] = $this->quoter->quote($column) . ' = ?';
		}

		return new Fragment(
			'UPDATE ' . $this->resolver->sql($state->table)
				. "\nSET " . implode(', ', $columns)
				. "\nWHERE " . $state->where->sql
				. $this->tail($state->orders, $state->limit, null),
			array_merge(array_values($values), $state->where->bindings),
		);
	}

	/**
	 * Compile a filtered, single-table delete.
	 *
	 * @throws InvalidArgumentException When the query cannot be used for a delete.
	 */
	public function delete(QueryState $state): Fragment {
		$this->writable($state);

		if ($state->table->alias !== null) {
			throw new InvalidArgumentException('Delete from an unaliased table for compatibility with supported MySQL and MariaDB versions.');
		}

		return new Fragment(
			'DELETE FROM ' . $this->resolver->sql($state->table)
				. "\nWHERE " . $state->where->sql
				. $this->tail($state->orders, $state->limit, null),
			$state->where->bindings,
		);
	}

	/**
	 * Reject insert state that cannot affect the inserted rows.
	 *
	 * @throws InvalidArgumentException When query options would be ignored by an insert.
	 */
	public function insertable(QueryState $state): void {
		if ($state->selection !== [] || $state->joins !== [] || $state->where->sql !== '' || $this->shaped($state) || $state->orders !== []) {
			throw new InvalidArgumentException('Start a fresh table query before inserting or upserting rows.');
		}
	}

	/**
	 * Identify selections whose result shape must survive aggregate wrapping.
	 */
	private function shaped(QueryState $state): bool {
		return in_array(null, array_column($state->selection, 'name'), true) || $state->distinct || $state->groups !== [] || $state->having->sql !== '' || $state->limit !== null || $state->offset !== null;
	}

	/**
	 * Reject writes whose filters or query options cannot be honored.
	 */
	private function writable(QueryState $state): void {
		if ($state->where->sql === '') {
			throw new InvalidArgumentException('Add a condition before updating or deleting; use Table::deleteAll() for an intentional full-table delete.');
		}

		if ($state->selection !== [] || $state->joins !== [] || $state->distinct || $state->groups !== [] || $state->having->sql !== '' || $state->offset !== null) {
			throw new InvalidArgumentException('Updates and deletes support conditions, ordering, and limit only.');
		}
	}

	/**
	 * Append ordering and pagination supported by MySQL and MariaDB.
	 *
	 * @param list<string> $orders
	 */
	private function tail(array $orders, ?int $limit, ?int $offset): string {
		$sql = $orders === [] ? '' : "\nORDER BY " . implode(', ', $orders);

		if ($limit !== null || $offset !== null) {
			$sql .= "\nLIMIT " . ($limit ?? '18446744073709551615');
		}

		if ($offset !== null) {
			$sql .= ' OFFSET ' . $offset;
		}

		return $sql;
	}
}
