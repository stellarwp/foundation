<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * Declare historical schema changes independently of application services.
 *
 * Migration files return an anonymous subclass; their filenames supply persistent IDs.
 */
abstract class Migration implements Contracts\Migration
{
	/**
	 * Stop rollback unless the migration supplies a safe inverse.
	 *
	 * @throws IrreversibleMigration Until down() is implemented.
	 */
	public function down(Blueprint $schema): void {
		throw new IrreversibleMigration('Implement a safe down() declaration before rolling back this migration.');
	}
}
