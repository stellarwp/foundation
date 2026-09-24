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
use StellarWP\Foundation\Migrations\Exceptions\IncompatibleSchema;
use StellarWP\Foundation\Migrations\Schema\ForeignKeyComparator;
use StellarWP\Foundation\Migrations\Schema\SchemaState;

/**
 * Physical aliases must identify exactly one historical relationship.
 */
final class ForeignKeyComparatorTest extends TestCase
{
	/**
	 * Prefix aliases retain the declared label while errors identify the actual database constraint.
	 */
	public function test_aliases_keep_logical_labels_in_both_planning_snapshots(): void {
		$config     = new ComparatorConfig();
		$platform   = new MySQL84Platform();
		$comparator = new ForeignKeyComparator($platform, new Comparator($platform, $config), $config);
		$historical = 'fk_' . str_repeat('a', 40);
		$live       = 'fk_' . str_repeat('b', 40);
		$before     = $this->schema($historical);

		$before->foreignKeyNames['items'][$historical] = 'order';

		$after  = clone $before;
		$actual = $this->schema($live);
		$comparator->alignForeignKeyNames($before, $actual, $after, '4');

		$this->assertSame('"order" (database constraint "' . $live . '")', $before->foreignKeyLabel('items', $live));
		$this->assertSame($before->foreignKeyLabel('items', $live), $after->foreignKeyLabel('items', $live));
		$this->assertSame($historical, $after->foreignKeyLabel('items', $historical));
		$this->assertTrue($comparator->compareSchemas($actual->schema, $after->schema)->isEmpty());
		$this->assertSame($live, $actual->foreignKeyLabel('items', $live));
	}

	public function test_two_historical_relationships_cannot_claim_the_same_live_constraint(): void {
		$config     = new ComparatorConfig();
		$platform   = new MySQL84Platform();
		$comparator = new ForeignKeyComparator($platform, new Comparator($platform, $config), $config);
		$before     = $this->schema('fk_' . str_repeat('a', 40), 'fk_' . str_repeat('b', 40));
		$actual     = $this->schema('fk_' . str_repeat('c', 40));
		$after      = clone $before;

		$this->expectException(IncompatibleSchema::class);
		$this->expectExceptionMessage('ambiguous foreign-key identity');
		$comparator->alignForeignKeyNames($before, $actual, $after, '4');
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
