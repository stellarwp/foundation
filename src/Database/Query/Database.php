<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;

/**
 * Query application tables and execute SQL with inferred parameter types.
 */
final readonly class Database
{
	/**
	 * Receive shared query services without opening a database connection.
	 *
	 * @internal Obtain this service through DatabaseProvider.
	 */
	public function __construct(
		private Compiler $compiler,
		private Executor $executor,
		private InsertWriter $writer,
		private TableReferenceResolver $resolver,
		private IdentifierQuoter $quoter,
	) {
	}

	/**
	 * Start a query capturing this table's current physical name.
	 *
	 * @throws InvalidArgumentException When the table alias is invalid.
	 * @throws DatabaseException        When the application table name is invalid.
	 */
	public function table(Table|string|TableReference $table, ?string $alias = null): Query {
		$reference = $this->resolver->resolve($table);

		if ($alias !== null) {
			$reference = $reference->as($alias);
		}

		return new Query(
			$this->compiler,
			$this->executor,
			$this->writer,
			$this->resolver,
			$reference,
			$this->quoter,
		);
	}

	/**
	 * Capture a WordPress core table, including its global or custom naming policy.
	 *
	 * @throws InvalidArgumentException When WordPress does not own the requested table.
	 */
	public function wordpress(string $name): TableReference {
		return $this->resolver->wordpress($name);
	}

	/**
	 * Execute application-owned SQL and return associative rows, or an empty list.
	 *
	 * Omitted types use the fluent builder's value normalization and type inference.
	 * Explicit types pass the original values to Doctrine for conversion.
	 *
	 * @param list<mixed>|array<string, mixed>                                        $bindings A zero-based positional list or named values, without mixing styles.
	 * @param array<int<0, max>|string, string|Type|ParameterType|ArrayParameterType> $types    Types keyed by zero-based position or parameter name.
	 *
	 * @throws InvalidArgumentException For unsupported values without an explicit type.
	 * @throws DatabaseException        When the managed session or transaction is invalid.
	 * @throws Exception                When execution or type conversion fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function select(string $sql, array $bindings = [], array $types = []): array {
		return $this->executor->fetchAllAssociative($sql, $bindings, $types);
	}

	/**
	 * Execute an application-owned SQL insert and return its affected-row count.
	 *
	 * Omitted types use the fluent builder's value normalization and type inference.
	 * Explicit types pass the original values to Doctrine for conversion.
	 *
	 * @param list<mixed>|array<string, mixed>                                        $bindings A zero-based positional list or named values, without mixing styles.
	 * @param array<int<0, max>|string, string|Type|ParameterType|ArrayParameterType> $types    Types keyed by zero-based position or parameter name.
	 *
	 * @throws InvalidArgumentException For unsupported values without an explicit type.
	 * @throws DatabaseException        When the managed session or transaction is invalid.
	 * @throws Exception                When execution or type conversion fails.
	 */
	public function insert(string $sql, array $bindings = [], array $types = []): int|string {
		return $this->executor->executeStatement($sql, $bindings, $types);
	}

	/**
	 * Execute an application-owned SQL update and return its affected-row count.
	 *
	 * Omitted types use the fluent builder's value normalization and type inference.
	 * Explicit types pass the original values to Doctrine for conversion.
	 *
	 * @param list<mixed>|array<string, mixed>                                        $bindings A zero-based positional list or named values, without mixing styles.
	 * @param array<int<0, max>|string, string|Type|ParameterType|ArrayParameterType> $types    Types keyed by zero-based position or parameter name.
	 *
	 * @throws InvalidArgumentException For unsupported values without an explicit type.
	 * @throws DatabaseException        When the managed session or transaction is invalid.
	 * @throws Exception                When execution or type conversion fails.
	 */
	public function update(string $sql, array $bindings = [], array $types = []): int|string {
		return $this->executor->executeStatement($sql, $bindings, $types);
	}

	/**
	 * Execute an application-owned SQL delete and return its affected-row count.
	 *
	 * Omitted types use the fluent builder's value normalization and type inference.
	 * Explicit types pass the original values to Doctrine for conversion.
	 *
	 * @param list<mixed>|array<string, mixed>                                        $bindings A zero-based positional list or named values, without mixing styles.
	 * @param array<int<0, max>|string, string|Type|ParameterType|ArrayParameterType> $types    Types keyed by zero-based position or parameter name.
	 *
	 * @throws InvalidArgumentException For unsupported values without an explicit type.
	 * @throws DatabaseException        When the managed session or transaction is invalid.
	 * @throws Exception                When execution or type conversion fails.
	 */
	public function delete(string $sql, array $bindings = [], array $types = []): int|string {
		return $this->executor->executeStatement($sql, $bindings, $types);
	}
}
