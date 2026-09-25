<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\AddCompositeReference;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\AddItemNote;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\AddOrderNote;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\AddOrderReference;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\CascadeOrderReference;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\CreateItems;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\CreateOrders;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\CreateReferencedItems;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\RemoveOrderReference;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\RenameOrderReference;

/**
 * Relationships enforce data integrity through creation, alteration, and reversal.
 */
final class ForeignKeyMigrationTest extends DatabaseTestCase
{
	private string $orders;
	private string $items;
	private string $history;

	protected function configuration(): array {
		return ['migrations' => ['table' => $this->suffix . '_history']];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->container->register(MigrationsProvider::class);
		$this->orders  = $this->privateTable($this->suffix . '_orders');
		$this->items   = $this->privateTable($this->suffix . '_items');
		$this->history = $this->privateTable($this->suffix . '_history');
	}

	public function test_resolving_migration_services_does_not_initialize_the_connection(): void {
		$db = $this->createMock(Connection::class);
		$db->expects($this->never())->method('getDatabasePlatform');
		$db->expects($this->never())->method('createSchemaManager');
		$this->container->singleton(Connection::class, $db);

		$this->assertInstanceOf(Migrator::class, $this->container->get(Migrator::class));
	}

	private function migrations(Migration ...$migrations): Migrator {
		foreach (array_values($migrations) as $position => $migration) {
			$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, [new MigrationRegistration((string) ($position + 1), $migration)]);
		}

		return $this->container->get(Migrator::class);
	}

	/** @return list<ForeignKeyConstraint> */
	private function constraints(): array {
		return array_values($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_items')->getForeignKeys());
	}

	private function rows(): void {
		$this->observer->insert($this->orders, ['id' => 1, 'account_id' => 10]);
		$this->observer->insert($this->items, ['id' => 2, 'order_id' => 1]);
	}

	public function test_create_enforces_cascade_and_rolls_back_child_before_parent(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateReferencedItems($this->suffix));
		$migrator->migrate();
		$this->rows();
		$this->observer->delete($this->orders, ['id' => 1]);
		$this->assertSame([], $this->observer->fetchAllAssociative('SELECT * FROM ' . $this->items));
		$this->assertCount(1, $this->constraints());
		$this->assertSame([], $migrator->preview());
		$migrator->rollbackTo(Migrator::NONE);
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->source->prefix . $this->suffix . '_orders']));
	}

	public function test_alter_defaults_to_restrict_and_drop_keeps_columns_indexes_and_rows(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix));
		$migrator->migrate();
		$this->rows();

		try {
			$this->observer->delete($this->orders, ['id' => 1]);
			$this->fail('Referenced rows must be protected by default.');
		} catch (ForeignKeyConstraintViolationException) {
			$this->assertCount(1, $this->constraints());
		}

		$migrator->rollback();
		$constraints = $this->constraints();
		$this->assertSame([], $constraints);
		$this->observer->delete($this->orders, ['id' => 1]);
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT order_id FROM ' . $this->items));
		$this->assertTrue($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_items')->hasIndex('order_lookup'));
	}

	public function test_replacement_changes_actions_and_rollback_restores_them(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix), new CascadeOrderReference($this->suffix));
		$migrator->migrate('3');
		$this->rows();
		$migrator->migrate();
		$this->observer->update($this->orders, ['id' => 3], ['id' => 1]);
		$this->assertSame(3, (int) $this->observer->fetchOne('SELECT order_id FROM ' . $this->items));
		$this->observer->delete($this->orders, ['id' => 3]);
		$this->assertSame([], $this->observer->fetchAllAssociative('SELECT * FROM ' . $this->items));
		$migrator->rollback();
		$this->rows();
		$this->expectException(ForeignKeyConstraintViolationException::class);
		$this->observer->delete($this->orders, ['id' => 1]);
	}

	public function test_composite_reference_clears_all_columns_on_update_and_delete(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddCompositeReference($this->suffix));
		$migrator->migrate();
		$this->observer->insert($this->orders, ['id' => 1, 'account_id' => 10]);
		$this->observer->insert($this->items, ['id' => 2, 'order_id' => 1, 'account_id' => 10]);
		$this->observer->update($this->orders, ['account_id' => 20], ['id' => 1]);
		$this->assertSame(['order_id' => null, 'account_id' => null], $this->observer->fetchAssociative('SELECT order_id, account_id FROM ' . $this->items));
		$this->observer->update($this->items, ['account_id' => 20, 'order_id' => 1], ['id' => 2]);
		$this->observer->delete($this->orders, ['id' => 1]);
		$row = $this->observer->fetchAssociative('SELECT order_id, account_id FROM ' . $this->items);
		$this->assertSame(['order_id' => null, 'account_id' => null], $row);
	}

	public function test_constraint_name_changes_are_not_lost_as_equivalent_definitions(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix), new RenameOrderReference($this->suffix));
		$migrator->migrate('3');
		$old   = $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue();
		$steps = $migrator->migrate();
		$this->assertNotEmpty($steps[0]->sql);
		$this->assertNotSame($old, $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue());
		$migrator->rollback();
		$this->assertSame($old, $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue());
	}

	public function test_replacement_stops_after_only_the_drop_completed(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix), new CascadeOrderReference($this->suffix));
		$migrator->migrate('3');
		$name = $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue();
		$this->assertNotNull($name);
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' DROP FOREIGN KEY ' . $this->observer->quoteSingleIdentifier($name));
		$this->expectException(MigrationInterrupted::class);
		$migrator->migrate();
	}

	public function test_conflicting_existing_constraint_is_not_silently_replaced(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix));
		$migrator->migrate();
		$name = $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue();
		$this->assertNotNull($name);
		$quoted = $this->observer->quoteSingleIdentifier($name);
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' DROP FOREIGN KEY ' . $quoted);
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' ADD CONSTRAINT ' . $quoted . ' FOREIGN KEY (order_id) REFERENCES ' . $this->orders . ' (id) ON DELETE CASCADE');
		$this->observer->delete($this->history, ['version' => '3']);
		$this->expectException(MigrationInterrupted::class);
		$this->expectExceptionMessage('already exists');
		$migrator->migrate();
	}

	/**
	 * @return iterable<string, array{bool, bool}>
	 */
	public static function foreignKeyDrift(): iterable {
		yield 'missing constraint on untouched table' => [
			false,
			false,
		];

		yield 'changed actions on untouched table' => [
			true,
			false,
		];

		yield 'missing constraint on altered table' => [
			false,
			true,
		];

		yield 'changed actions on altered table' => [
			true,
			true,
		];
	}

	/**
	 * Pending operations do not repair or reject unrelated relationship changes.
	 *
	 * @dataProvider foreignKeyDrift
	 */
	#[DataProvider('foreignKeyDrift')]
	public function test_unrelated_relationship_drift_is_preserved(bool $replace, bool $alterItems): void {
		$pending  = $alterItems ? new AddItemNote($this->suffix) : new AddOrderNote($this->suffix);
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix), $pending);
		$migrator->migrate('3');
		$name = $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue();
		$this->assertNotNull($name);
		$quoted = $this->observer->quoteSingleIdentifier($name);
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' DROP FOREIGN KEY ' . $quoted);

		if ($replace) {
			$this->observer->executeStatement("ALTER TABLE {$this->items}
				ADD CONSTRAINT $quoted FOREIGN KEY (order_id)
				REFERENCES {$this->orders} (id) ON DELETE CASCADE");
		}

		$migrator->migrate();
		$pendingTable = $this->source->prefix . $this->suffix . ($alterItems ? '_items' : '_orders');
		$this->assertTrue($this->observer->createSchemaManager()->introspectTable($pendingTable)->hasColumn('note'));
		$this->assertCount($replace ? 1 : 0, $this->constraints());

		if ($replace) {
			$this->assertSame('CASCADE', $this->constraints()[0]->onDelete());
		}
	}

	public function test_an_equivalent_unrelated_foreign_key_is_preserved(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix));
		$migrator->migrate('2');
		$external = $this->suffix . '_external';
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' ADD CONSTRAINT ' . $this->observer->quoteSingleIdentifier($external) . ' FOREIGN KEY (order_id) REFERENCES ' . $this->orders . ' (id)');
		$migrator->migrate();
		$this->assertCount(2, $this->constraints());
		$migrator->rollback();
		$constraints = $this->constraints();
		$this->assertCount(1, $constraints);
		$this->assertSame($external, $constraints[0]->getObjectName()?->getIdentifier()->getValue());
	}

	public function test_create_retry_rejects_a_conflicting_foreign_key(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateReferencedItems($this->suffix));
		$migrator->migrate();
		$name = $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue();
		$this->assertNotNull($name);
		$quoted = $this->observer->quoteSingleIdentifier($name);
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' DROP FOREIGN KEY ' . $quoted);
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' ADD CONSTRAINT ' . $quoted . ' FOREIGN KEY (order_id) REFERENCES ' . $this->orders . ' (id)');
		$this->observer->delete($this->history, ['version' => '2']);
		$this->expectException(MigrationInterrupted::class);
		$this->expectExceptionMessage('already exists');
		$migrator->migrate();
	}

	/**
	 * Resume rollback after the child table was dropped but its ledger entry remains.
	 */
	public function test_rollback_retry_requires_repair_when_the_table_is_already_gone(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateReferencedItems($this->suffix));
		$migrator->migrate();
		$this->observer->executeStatement('DROP TABLE ' . $this->items);

		$this->expectException(MigrationInterrupted::class);
		$migrator->rollback();
	}

	public function test_the_same_migrations_use_distinct_foreign_keys_on_another_site(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateReferencedItems($this->suffix));
		$migrator->migrate();
		$original = $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue();
		$site     = $this->factory()->blog->create();
		$this->assertIsInt($site);
		switch_to_blog($site);
		$this->source->set_blog_id($site);

		try {
			$this->privateTable($this->suffix . '_orders');
			$this->privateTable($this->suffix . '_items');
			$this->privateTable($this->suffix . '_history');
			$migrator->migrate();
			$constraint = $this->constraints()[0];
			$this->assertNotSame($original, $constraint->getObjectName()?->getIdentifier()->getValue());
			$this->assertSame($this->source->prefix . $this->suffix . '_orders', $constraint->getReferencedTableName()->getUnqualifiedName()->getValue());
		} finally {
			restore_current_blog();
			$this->source->set_blog_id(get_current_blog_id());
		}
	}

	public function test_removal_keeps_the_automatically_supplied_index_and_data(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateReferencedItems($this->suffix), new RemoveOrderReference($this->suffix));
		$migrator->migrate('2');
		$this->rows();
		$tableName = $this->source->prefix . $this->suffix . '_items';
		$before    = array_keys($this->observer->createSchemaManager()->introspectTable($tableName)->getIndexes());
		$migrator->migrate();
		$after = array_keys($this->observer->createSchemaManager()->introspectTable($tableName)->getIndexes());
		$this->assertSame($before, $after);
		$this->assertCount(2, $after);
		$this->assertSame([], $this->constraints());
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->items));
		$migrator->rollback();
		$this->assertCount(1, $this->constraints());
	}

	public function test_existing_orphans_fail_without_recording_the_relationship(): void {
		$migrator = $this->migrations(new CreateOrders($this->suffix), new CreateItems($this->suffix), new AddOrderReference($this->suffix));
		$migrator->migrate('2');
		$this->observer->insert($this->items, ['id' => 1, 'order_id' => 99]);

		try {
			$migrator->migrate();
			$this->fail('Existing orphan rows must prevent adding the foreign key.');
		} catch (ForeignKeyConstraintViolationException) {
			$this->assertSame(['1', '2'], $this->observer->fetchFirstColumn('SELECT version FROM ' . $this->history . ' ORDER BY version'));
		}
	}
}
