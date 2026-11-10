<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\Factories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\ComparatorConfig;
use StellarWP\Foundation\Migrations\Schema\ForeignKeyComparator;

/**
 * Build a migration comparator for the active database platform when planning begins.
 *
 * @internal
 */
final readonly class MigrationComparatorFactory
{
	/**
	 * Receive the shared connection and comparison policy without inspecting the database.
	 */
	public function __construct(
		private Connection $db,
		private ComparatorConfig $config,
	) {
	}

	/**
	 * Configure native platform comparison and Foundation's constraint identity policy together.
	 *
	 * @throws Exception When platform detection or schema setup fails.
	 */
	public function create(): ForeignKeyComparator {
		$native = $this->db->createSchemaManager()->createComparator($this->config);

		return new ForeignKeyComparator(
			$this->db->getDatabasePlatform(),
			$native,
			$this->config,
		);
	}
}
