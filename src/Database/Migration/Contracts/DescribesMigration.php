<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Contracts;

/**
 * Optional human-readable description shown in migration status output.
 */
interface DescribesMigration
{
	/**
	 * Summarize the change in one line.
	 */
	public function describe(): string;
}
