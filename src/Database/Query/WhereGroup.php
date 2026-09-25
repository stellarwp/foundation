<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use Closure;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Query\ValueObjects\Fragment;
use StellarWP\Foundation\Database\Query\ValueObjects\Predicate;

/**
 * Build grouped conditions with bound values and explicit boolean relationships.
 */
class WhereGroup
{
	/**
	 * @var list<Predicate>
	 */
	private array $predicates = [];

	/**
	 * Receive identifier quoting shared by all conditions.
	 *
	 * @internal Groups are produced by the owning query.
	 */
	public function __construct(
		protected readonly IdentifierQuoter $quoter,
	) {
	}

	/**
	 * Add equality, an explicit comparison, or a nested condition group.
	 *
	 * @param string|Closure((static is JoinClause ? JoinClause : self)): mixed|array<array-key, mixed> $column Array criteria are validated to require string column names.
	 *
	 * @throws InvalidArgumentException When a comparison cannot be represented safely.
	 */
	public function where(string|Closure|array $column, mixed $operator = null, mixed $value = null): static {
		return $this->comparison($column, $operator, $value, func_num_args(), 'AND');
	}

	/**
	 * Add an alternative comparison or nested group.
	 *
	 * @param string|Closure((static is JoinClause ? JoinClause : self)): mixed|array<array-key, mixed> $column Array criteria are validated to require string column names.
	 *
	 * @throws InvalidArgumentException When a comparison cannot be represented safely.
	 */
	public function orWhere(string|Closure|array $column, mixed $operator = null, mixed $value = null): static {
		return $this->comparison($column, $operator, $value, func_num_args(), 'OR');
	}

	/**
	 * Match one of the supplied values; an empty list matches no rows.
	 *
	 * @param list<mixed> $values
	 *
	 * @throws InvalidArgumentException When the column is invalid.
	 */
	public function whereIn(string $column, array $values): static {
		return $this->in($column, $values, false);
	}

	/**
	 * Exclude supplied values; an empty list matches all rows.
	 *
	 * @param list<mixed> $values
	 *
	 * @throws InvalidArgumentException When the column is invalid.
	 */
	public function whereNotIn(string $column, array $values): static {
		return $this->in($column, $values, true);
	}

	/**
	 * Match a missing value.
	 *
	 * @throws InvalidArgumentException When the column is invalid.
	 */
	public function whereNull(string $column): static {
		return $this->add(new Fragment($this->quoter->column($column) . ' IS NULL'), 'AND');
	}

	/**
	 * Match a present value.
	 *
	 * @throws InvalidArgumentException When the column is invalid.
	 */
	public function whereNotNull(string $column): static {
		return $this->add(new Fragment($this->quoter->column($column) . ' IS NOT NULL'), 'AND');
	}

	/**
	 * Match any literal substring, escaping SQL wildcard characters in every needle.
	 *
	 * @param list<string> $needles An empty list matches no rows.
	 *
	 * @throws InvalidArgumentException When the column is invalid.
	 */
	public function whereContainsAny(string $column, array $needles): static {
		$name     = $this->quoter->column($column);
		$patterns = [];

		foreach ($needles as $needle) {
			$patterns[] = '%' . strtr($needle, [
				'!' => '!!',
				'%' => '!%',
				'_' => '!_',
			]) . '%';
		}

		$sql = $patterns === [] ? '0 = 1' : '(' . implode(' OR ', array_fill(0, count($patterns), $name . " LIKE ? ESCAPE '!'")) . ')';

		return $this->add(new Fragment($sql, $patterns), 'AND');
	}

	/**
	 * Render this group in declaration order.
	 *
	 * @internal
	 */
	public function condition(): Fragment {
		$sql      = '';
		$bindings = [];

		foreach ($this->predicates as $predicate) {
			$sql .= ($sql === '' ? '' : ' ' . $predicate->boolean . ' ') . $predicate->fragment->sql;
			array_push($bindings, ...$predicate->fragment->bindings);
		}

		return new Fragment($sql, $bindings);
	}

	/**
	 * Add one already validated predicate.
	 *
	 * @internal
	 */
	protected function add(Fragment $fragment, string $boolean): static {
		$this->predicates[] = new Predicate($fragment, $boolean);

		return $this;
	}

	/**
	 * Validate comparison operators before interpreting null values.
	 *
	 * @internal
	 */
	protected function operator(mixed $operator): string {
		if (! is_string($operator) || ! in_array($operator, [
			'=',
			'!=',
			'<>',
			'<',
			'<=',
			'>',
			'>=',
		], true)) {
			throw new InvalidArgumentException('Use a supported comparison operator: =, !=, <>, <, <=, >, >=.');
		}

		return $operator;
	}

	/**
	 * Create a nested condition group with this clause's supported operations.
	 *
	 * @internal
	 */
	protected function newGroup(): self {
		return new self($this->quoter);
	}

	/**
	 * Normalize the public comparison forms at their shared boundary.
	 *
	 * @param string|Closure((static is JoinClause ? JoinClause : self)): mixed|array<array-key, mixed> $column Array criteria are validated to require string column names.
	 */
	private function comparison(string|Closure|array $column, mixed $operator, mixed $value, int $arguments, string $boolean): static {
		if ($column instanceof Closure || is_array($column)) {
			$group = $this->newGroup();

			if ($column instanceof Closure) {
				$column($group);
			} else {
				foreach ($column as $name => $equal) {
					if (! is_string($name)) {
						throw new InvalidArgumentException('Criteria must use column names as string keys.');
					}

					$group->where($name, $equal);
				}
			}

			$fragment = $group->condition();

			if ($fragment->sql === '') {
				throw new InvalidArgumentException('A condition group must contain at least one condition.');
			}

			return $this->add(new Fragment('(' . $fragment->sql . ')', $fragment->bindings), $boolean);
		}

		if ($arguments === 2) {
			$value    = $operator;
			$operator = '=';
		}

		$operator = $this->operator($operator);
		$name     = $this->quoter->column($column);

		if ($value !== null) {
			return $this->add(new Fragment($name . ' ' . $operator . ' ?', [
				$value,
			]), $boolean);
		}

		if (! in_array($operator, [
			'=',
			'!=',
			'<>',
		], true)) {
			throw new InvalidArgumentException('Use whereNull() or whereNotNull() when comparing null.');
		}

		return $this->add(new Fragment($name . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL')), $boolean);
	}

	/**
	 * Build list membership without delegating array expansion to the driver.
	 *
	 * @param list<mixed> $values
	 */
	private function in(string $column, array $values, bool $negated): static {
		$name = $this->quoter->column($column);

		if ($values === []) {
			return $this->add(new Fragment($negated ? '1 = 1' : '0 = 1'), 'AND');
		}

		return $this->add(new Fragment($name . ($negated ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, count($values), '?')) . ')', $values), 'AND');
	}
}
