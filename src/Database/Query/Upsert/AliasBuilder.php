<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\Upsert;

use StellarWP\Foundation\Database\Query\IdentifierQuoter;
use StellarWP\Foundation\Database\Query\Upsert\Contracts\Builder;

/**
 * Uses the incoming row alias available since MySQL 8.0.19.
 *
 * @internal
 */
final readonly class AliasBuilder implements Builder
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
		$alias       = strcasecmp($table, 'foundation_incoming') === 0 ? '_foundation_incoming' : 'foundation_incoming';
		$quotedAlias = $this->quoter->quote($alias);
		$assignments = [];

		foreach ($columns as $column) {
			$quoted        = $this->quoter->quote($column);
			$assignments[] = $quoted . ' = ' . $quotedAlias . '.' . $quoted;
		}

		return ' AS ' . $quotedAlias . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
	}
}
