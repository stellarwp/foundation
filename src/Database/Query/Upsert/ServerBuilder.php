<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\Upsert;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use LogicException;
use StellarWP\Foundation\Database\Query\Upsert\Contracts\Builder;

/**
 * Selects the upsert strategy on first use, leaving read-only composition offline.
 *
 * @internal
 */
final class ServerBuilder implements Builder
{
	private ?Builder $builder = null;

	/**
	 * Defer platform discovery until a nonempty upsert executes.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly AliasBuilder $aliasBuilder,
		private readonly ValueBuilder $valueBuilder,
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws LogicException For unsupported database platforms.
	 * @throws Exception      When server discovery fails.
	 */
	public function build(array $columns, string $table): string {
		if ($this->builder === null) {
			$platform = $this->connection->getDatabasePlatform();

			if (! $platform instanceof AbstractMySQLPlatform) {
				throw new LogicException('The query builder supports MySQL and MariaDB only.');
			}

			$this->builder = $platform instanceof MariaDBPlatform || version_compare(explode('-', $this->connection->getServerVersion(), 2)[0], '8.0.19', '<')
				? $this->valueBuilder
				: $this->aliasBuilder;
		}

		return $this->builder->build($columns, $table);
	}
}
