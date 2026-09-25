<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;

/**
 * Start fresh application queries and resolve WordPress-owned tables.
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
}
