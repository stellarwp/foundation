<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Migrations;

use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Migrations\Schema\ForeignKeyComparator;
use StellarWP\Foundation\Migrations\Schema\SchemaState;

/**
 * Constraint comparison preserves explicitly declared identities.
 */
final class ForeignKeyComparatorTest extends TestCase
{
	/**
	 * Changing the declared name must replace even a structurally equivalent constraint.
	 */
	public function test_distinct_names_require_drop_and_add(): void {
		$config     = new ComparatorConfig();
		$platform   = new MySQL84Platform();
		$comparator = new ForeignKeyComparator($platform, new Comparator($platform, $config), $config);
		$before     = $this->schema('original');
		$after      = $this->schema('replacement');
		$diff       = $comparator->compareTables($before->schema->getTable('items'), $after->schema->getTable('items'));

		$this->assertCount(1, $diff->getAddedForeignKeys());
		$this->assertCount(1, $diff->getDroppedForeignKeyConstraintNames());
	}

	/**
	 * Model equivalent relationships with distinct physical names.
	 *
	 * @param non-empty-string ...$names
	 */
	private function schema(string ...$names): SchemaState {
		$table = Table::editor()->setUnquotedName('items')->addColumn(
			Column::editor()->setUnquotedName('order_id')->setTypeName(Types::INTEGER)->create(),
		);

		foreach ($names as $name) {
			$table->addForeignKeyConstraint(ForeignKeyConstraint::editor()
				->setUnquotedName($name)
				->setUnquotedReferencingColumnNames('order_id')
				->setUnquotedReferencedTableName('orders')
				->setUnquotedReferencedColumnNames('id')
				->create());
		}

		return new SchemaState(new Schema([
			$table->create(),
		]));
	}
}
