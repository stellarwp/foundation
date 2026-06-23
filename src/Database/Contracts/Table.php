<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the identity and schema for a database table.
 */
interface Table
{
	public function id(): string;

	public function name(): string;

	public function definition(): TableDefinition;
}
