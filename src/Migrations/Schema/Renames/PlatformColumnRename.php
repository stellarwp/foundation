<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\Renames;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDB1052Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\Table;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;

/**
 * Select column-rename SQL lazily, when migration planning first needs the server.
 *
 * @internal
 */
final readonly class PlatformColumnRename implements ColumnRename
{
	/**
	 * Receive the server connection and the two supported rename strategies.
	 */
	public function __construct(
		private Connection $db,
		private NativeColumnRename $native,
		private ChangeColumnRename $change,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function inspect(Table $table): Table {
		return $this->strategy()->inspect($table);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sql(Table $table, string $from, string $to): array {
		return $this->strategy()->sql($table, $from, $to);
	}

	private function strategy(): ColumnRename {
		$platform = $this->db->getDatabasePlatform();

		return $platform instanceof MySQL80Platform || $platform instanceof MariaDB1052Platform
			? $this->native
			: $this->change;
	}
}
