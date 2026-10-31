<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\Upsert;

use StellarWP\Foundation\Database\Query\IdentifierQuoter;
use StellarWP\Foundation\Database\Query\Upsert\Contracts\Builder;

/**
 * Uses the incoming VALUES() expression supported by MariaDB and older MySQL.
 *
 * @internal
 */
final readonly class ValueBuilder implements Builder
{
	/**
	 * Use the shared identifier quoting service.
	 */
	public function __construct(
		private IdentifierQuoter $quoter,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function build(array $columns, string $table): string {
		$assignments = [];

		foreach ($columns as $column) {
			$quoted        = $this->quoter->quote($column);
			$assignments[] = $quoted . ' = VALUES(' . $quoted . ')';
		}

		return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
	}
}
