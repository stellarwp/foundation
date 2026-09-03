<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Defines a versioned database change.
 */
interface Migration
{
	/**
	 * Return a unique, stable identifier that is nonblank, unpadded,
	 * non-integer-like, and no longer than 191 bytes.
	 */
	public function id(): string;

	/**
	 * Apply the migration.
	 */
	public function up(Schema $schema): void;

	/**
	 * Reverse the migration when supported.
	 */
	public function down(Schema $schema): void;
}
