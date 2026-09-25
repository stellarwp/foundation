<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Migrations\Schema\Blueprint;
use StellarWP\Foundation\Migrations\Schema\SchemaState;
use StellarWP\Foundation\Migrations\Schema\TableBlueprint;

/**
 * Relationship declarations require a complete target and stable table-local identity.
 */
final class ForeignKeyDefinitionTest extends TestCase
{
	public function test_local_columns_are_required(): void {
		$this->expectException(InvalidArgumentException::class);
		(new TableBlueprint('items'))->foreignKey('order');
	}

	public function test_a_logical_name_cannot_be_empty(): void {
		$this->expectException(InvalidArgumentException::class);
		(new TableBlueprint('items'))->foreignKey('   ', 'order_id');
	}

	public function test_referenced_column_count_must_match(): void {
		$this->expectException(InvalidArgumentException::class);
		(new TableBlueprint('items'))->foreignKey('order', 'account_id', 'order_id')->references('orders', 'id');
	}

	public function test_missing_reference_is_reported_when_the_declaration_is_completed(): void {
		$names = $this->createStub(TableNameResolver::class);
		$names->method('tableName')->willReturn('wp_items');
		$blueprint = new Blueprint(new SchemaState(new Schema()), $names);
		$table     = $blueprint->create('items');
		$table->unsignedBigInteger('order_id');
		$table->foreignKey('order', 'order_id');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('references()');
		$blueprint->apply();
	}

	public function test_constraint_identity_is_scoped_to_the_physical_child_table(): void {
		$constraints = [];
		foreach (['wp_', 'wp_2_'] as $prefix) {
			$names = $this->createStub(TableNameResolver::class);
			$names->method('tableName')->willReturnMap([['items', $prefix . 'items'], ['orders', $prefix . 'orders']]);
			$state     = new SchemaState(new Schema());
			$blueprint = new Blueprint($state, $names);
			$table     = $blueprint->create('items');
			$table->unsignedBigInteger('order_id');
			$table->foreignKey('order', 'order_id')->references('orders', 'id');
			$blueprint->apply();
			$foreignKey    = array_values($state->schema->getTable($prefix . 'items')->getForeignKeys())[0];
			$constraints[] = $foreignKey->getObjectName()?->getIdentifier()->getValue();
			$this->assertSame($prefix . 'orders', $foreignKey->getReferencedTableName()->toString());
		}

		$this->assertNotSame($constraints[0], $constraints[1]);
	}

	/**
	 * Logical identities remain discoverable from live constraint names after renames.
	 */
	public function test_logical_identity_survives_table_and_column_renames(): void {
		$names = $this->createStub(TableNameResolver::class);
		$names->method('tableName')->willReturnCallback(static fn (string $name): string => 'wp_' . $name);
		$state     = new SchemaState(new Schema());
		$blueprint = new Blueprint($state, $names);
		$table     = $blueprint->create('items');
		$table->unsignedBigInteger('order_id');
		$table->foreignKey('order', 'order_id')->references('orders', 'id');
		$blueprint->apply();
		$constraint = array_key_first($state->schema->getTable('wp_items')->getForeignKeys());
		$this->assertNotNull($constraint);
		$this->assertSame(64, strlen($constraint));

		$blueprint->table('items')->renameColumn('order_id', 'purchase_id');
		$blueprint->rename('items', 'archive');
		$blueprint->apply();
		$this->assertTrue($state->schema->getTable('wp_archive')->hasForeignKey($constraint));

		// A fresh blueprint has no historical naming metadata.
		$drop = new Blueprint($state, $names);
		$drop->table('archive')->dropForeignKey('order');
		$drop->apply();
		$this->assertSame([], $state->schema->getTable('wp_archive')->getForeignKeys());
	}

	/**
	 * A logical removal must not guess between constraints combined from different tables.
	 */
	public function test_ambiguous_logical_identity_requires_an_explicit_repair(): void {
		$names = $this->createStub(TableNameResolver::class);
		$names->method('tableName')->willReturnCallback(static fn (string $name): string => 'wp_' . $name);
		$state     = new SchemaState(new Schema());
		$blueprint = new Blueprint($state, $names);

		foreach ([
			'items',
			'archive',
		] as $name) {
			$table = $blueprint->create($name);
			$table->unsignedBigInteger('order_id');
			$table->foreignKey('order', 'order_id')->references('orders', 'id');
		}

		$blueprint->apply();
		$archivedKey   = array_values($state->schema->getTable('wp_archive')->getForeignKeys())[0];
		$combined      = $state->schema->getTable('wp_items')->edit()->addForeignKeyConstraint($archivedKey)->create();
		$state->schema = new Schema([
			$combined,
		]);
		$drop = new Blueprint($state, $names);
		$drop->table('items')->dropForeignKey('order');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('More than one constraint has the logical name order on table wp_items.');
		$drop->apply();
	}

	/**
	 * Removing a relationship preserves both its backing index and the separate primary key.
	 */
	public function test_dropping_a_relationship_preserves_its_implicit_index_and_primary_key(): void {
		$names = $this->createStub(TableNameResolver::class);
		$names->method('tableName')->willReturnMap([['items', 'wp_items'], ['orders', 'wp_orders']]);
		$state  = new SchemaState(new Schema());
		$create = new Blueprint($state, $names);
		$table  = $create->create('items');
		$table->bigIncrements('id');
		$table->unsignedBigInteger('order_id');
		$table->foreignKey('order', 'order_id')->references('orders', 'id');
		$create->apply();
		$indexes = array_keys($state->schema->getTable('wp_items')->getIndexes());
		$this->assertCount(2, $indexes);

		$drop = new Blueprint($state, $names);
		$drop->table('items')->dropForeignKey('order');
		$drop->apply();
		$remaining = $state->schema->getTable('wp_items');

		$this->assertEqualsCanonicalizing($indexes, array_keys($remaining->getIndexes()));
		$this->assertNotNull($remaining->getPrimaryKeyConstraint());
		$this->assertSame([], $remaining->getForeignKeys());
		$this->assertTrue($remaining->hasColumn('order_id'));
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function actions(): iterable {
		yield 'cascade delete' => ['cascadeOnDelete', 'delete', 'CASCADE'];

		yield 'restrict delete' => ['restrictOnDelete', 'delete', 'RESTRICT'];

		yield 'null delete' => ['nullOnDelete', 'delete', 'SET NULL'];

		yield 'cascade update' => ['cascadeOnUpdate', 'update', 'CASCADE'];

		yield 'restrict update' => ['restrictOnUpdate', 'update', 'RESTRICT'];

		yield 'null update' => ['nullOnUpdate', 'update', 'SET NULL'];
	}

	/** @dataProvider actions */
	#[DataProvider('actions')]
	public function test_action_methods_translate_to_the_declared_database_behavior(string $method, string $event, string $action): void {
		$names = $this->createStub(TableNameResolver::class);
		$names->method('tableName')->willReturnMap([['items', 'wp_items'], ['orders', 'wp_orders']]);
		$state     = new SchemaState(new Schema());
		$blueprint = new Blueprint($state, $names);
		$table     = $blueprint->create('items');
		$table->unsignedBigInteger('order_id')->nullable();
		$table->foreignKey('order', 'order_id')->references('orders', 'id')->$method();
		$blueprint->apply();
		$foreignKey = array_values($state->schema->getTable('wp_items')->getForeignKeys())[0];
		$this->assertSame($action, ($event === 'delete' ? $foreignKey->getOnDeleteAction() : $foreignKey->getOnUpdateAction())->value);
	}
}
