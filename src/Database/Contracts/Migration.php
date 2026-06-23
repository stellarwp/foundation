<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Defines a reversible database schema change.
 */
interface Migration
{
	/**
	 * Unique, stable migration identifier.
	 */
	public function id(): string;

	/**
	 * Apply the migration.
	 */
	public function up(Schema $schema): void;

	/**
	 * Reverse the migration.
	 */
	public function down(Schema $schema): void;
}
