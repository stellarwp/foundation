<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Exceptions\IncompatibleSchema;
use StellarWP\Foundation\Migrations\Exceptions\LedgerFailure;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\AddItemNote;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\AddOrderReference;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\CascadeOrderReference;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\CreateItems;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\CreateOrders;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\ForeignKeys\RenameOrderReference;

/**
 * Historical relationships remain manageable after an external WordPress prefix change.
 */
final class ForeignKeyIdentityTest extends DatabaseTestCase
{
	private string $orders;
	private string $items;
	private string $history;

	protected function configuration(): array {
		return [
			'migrations' => [
				'table' => $this->suffix . '_history',
			],
		];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->container->register(MigrationsProvider::class);
		$this->trackTables();
	}

	public function test_unrelated_changes_and_rollback_keep_the_existing_relationship_and_data(): void {
		$migrator = $this->migrations(new AddItemNote($this->suffix));
		$migrator->migrate('3');
		$this->observer->insert($this->orders, [
			'id'         => 1,
			'account_id' => 10,
		]);
		$this->observer->insert($this->items, [
			'id'       => 2,
			'order_id' => 1,
		]);
		$original = $this->constraintName();
		$this->changePrefix();

		$preview = $migrator->preview();
		$this->assertStringNotContainsString('FOREIGN KEY', implode('; ', $preview[0]->sql));
		$steps = $migrator->migrate();
		$this->assertSame($preview[0]->sql, $steps[0]->sql);
		$this->assertSame($original, $this->constraintName());
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT order_id FROM ' . $this->items));
		$migrator->rollback();
		$this->assertSame($original, $this->constraintName());
		$migrator->rollback();
		$this->assertSame([], $this->constraints());
	}

	public function test_replacement_retry_recognizes_the_new_actions_under_the_old_physical_name(): void {
		$migrator = $this->migrations(new CascadeOrderReference($this->suffix));
		$migrator->migrate('3');
		$original = $this->constraintName();
		$this->changePrefix();
		$trigger = $this->observer->quoteSingleIdentifier($this->suffix . '_reject_history');
		$this->observer->executeStatement("CREATE TRIGGER $trigger
			BEFORE INSERT ON {$this->history}
			FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger rejected'");

		try {
			$migrator->migrate();
			$this->fail('The ledger write must fail after replacing the relationship.');
		} catch (LedgerFailure) {
			$this->assertSame($original, $this->constraintName());
			$this->assertSame('CASCADE', $this->constraints()[0]->onDelete());
		} finally {
			$this->observer->executeStatement('DROP TRIGGER ' . $trigger);
		}

		$retry = $migrator->migrate();
		$this->assertSame([], $retry[0]->sql);
		$this->assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->history} WHERE version = '4'"));
		$migrator->rollback();
		$this->assertSame($original, $this->constraintName());
		$this->assertNull($this->constraints()[0]->onDelete());
	}

	public function test_logical_name_changes_still_replace_the_constraint(): void {
		$migrator = $this->migrations(new RenameOrderReference($this->suffix));
		$migrator->migrate('3');
		$original = $this->constraintName();
		$this->changePrefix();
		$steps = $migrator->migrate();
		$this->assertNotEmpty($steps[0]->sql);
		$this->assertNotSame($original, $this->constraintName());
		$this->assertCount(1, $this->constraints());
		$this->observer->delete($this->history, [
			'version' => '4',
		]);
		$this->assertSame([], $migrator->migrate()[0]->sql);
		$migrator->rollback();
		$this->assertCount(1, $this->constraints());
		$migrator->rollback();
		$this->assertSame([], $this->constraints());
	}

	public function test_replacement_resumes_when_only_the_old_constraint_was_dropped(): void {
		$migrator = $this->migrations(new CascadeOrderReference($this->suffix));
		$migrator->migrate('3');
		$original = $this->constraintName();
		$this->changePrefix();
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' DROP FOREIGN KEY ' . $this->observer->quoteSingleIdentifier($original));
		$migrator->migrate();
		$this->assertCount(1, $this->constraints());
		$this->assertSame('CASCADE', $this->constraints()[0]->onDelete());
		$migrator->rollback();
		$migrator->rollback();
		$this->assertSame([], $this->constraints());
	}

	public function test_equivalent_external_constraints_are_preserved(): void {
		$migrator = $this->migrations(new AddItemNote($this->suffix));
		$migrator->migrate('3');
		$this->changePrefix();
		$external = $this->suffix . '_external';
		$this->addConstraint($external);
		$migrator->migrate();
		$migrator->rollback();
		$migrator->rollback();
		$this->assertCount(1, $this->constraints());
		$this->assertSame($external, $this->constraintName());
	}

	public function test_ambiguous_managed_names_fail_before_any_ddl(): void {
		$migrator = $this->migrations(new AddItemNote($this->suffix));
		$migrator->migrate('3');
		$this->changePrefix();
		$this->addConstraint('fk_' . sha1($this->suffix));

		try {
			$migrator->migrate();
			$this->fail('Two equivalent candidates must not be assigned arbitrary ownership.');
		} catch (IncompatibleSchema $failure) {
			$this->assertStringContainsString('ambiguous foreign-key identity for "order" (database constraint "', $failure->getMessage());
			$this->assertStringContainsString($this->suffix . '_items', $failure->getMessage());
			$this->assertFalse($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_items')->hasColumn('note'));
		}
	}

	public function test_changed_actions_are_not_accepted_as_an_unrelated_migration(): void {
		$migrator = $this->migrations(new AddItemNote($this->suffix));
		$migrator->migrate('3');
		$original = $this->constraintName();
		$this->changePrefix();
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' DROP FOREIGN KEY ' . $this->observer->quoteSingleIdentifier($original));
		$this->addConstraint($original, ' ON DELETE CASCADE');
		$this->expectException(IncompatibleSchema::class);
		$migrator->migrate();
	}

	public function test_removing_a_relationship_with_uncertain_ownership_does_not_record_success(): void {
		$migrator = $this->migrations();
		$migrator->migrate();
		$original = $this->constraintName();
		$this->changePrefix();
		$this->observer->executeStatement('ALTER TABLE ' . $this->items . ' DROP FOREIGN KEY ' . $this->observer->quoteSingleIdentifier($original));
		$this->addConstraint($original, ' ON DELETE CASCADE');

		try {
			$migrator->rollback();
			$this->fail('Uncertain ownership must not be treated as an already removed constraint.');
		} catch (IncompatibleSchema $failure) {
			$this->assertStringContainsString($original, $failure->getMessage());
			$this->assertStringContainsString('historical foreign key "order" (database constraint "', $failure->getMessage());
			$this->assertStringContainsString('different update/delete actions', $failure->getMessage());
			$this->assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->history} WHERE version = '3'"));
		}
	}

	public function test_full_definition_identifies_a_constraint_among_different_actions(): void {
		$migrator = $this->migrations(new AddItemNote($this->suffix));
		$migrator->migrate('3');
		$this->changePrefix();
		$external = 'fk_' . sha1($this->suffix);
		$this->addConstraint($external, ' ON DELETE CASCADE');
		$migrator->migrate();
		$migrator->rollback();
		$migrator->rollback();
		$this->assertCount(1, $this->constraints());
		$this->assertSame($external, $this->constraintName());
	}

	public function test_a_new_relationship_does_not_adopt_an_existing_managed_name(): void {
		$migrator = $this->migrations();
		$migrator->migrate('2');
		$this->changePrefix();
		$external = 'fk_' . sha1($this->suffix);
		$this->addConstraint($external);
		$migrator->migrate();
		$this->assertCount(2, $this->constraints());
		$migrator->rollback();
		$this->assertCount(1, $this->constraints());
		$this->assertSame($external, $this->constraintName());
	}

	public function test_exact_names_take_priority_over_equivalent_managed_names(): void {
		$migrator = $this->migrations(new AddItemNote($this->suffix));
		$migrator->migrate('3');
		$external = 'fk_' . sha1($this->suffix);
		$this->addConstraint($external);
		$migrator->migrate();
		$migrator->rollback();
		$migrator->rollback();
		$this->assertCount(1, $this->constraints());
		$this->assertSame($external, $this->constraintName());
	}

	public function test_preview_carries_identity_through_multiple_changes(): void {
		$migrator = $this->migrations(new CascadeOrderReference($this->suffix), new AddItemNote($this->suffix));
		$migrator->migrate('3');
		$this->changePrefix();
		$preview = $migrator->preview();
		$steps   = $migrator->migrate();
		$this->assertCount(2, $steps);
		$this->assertSame($preview[0]->sql, $steps[0]->sql);
		$this->assertSame($preview[1]->sql, $steps[1]->sql);
		$this->assertStringNotContainsString('FOREIGN KEY', implode('; ', $steps[1]->sql));
		$migrator->rollbackTo('2');
		$this->assertSame([], $this->constraints());
	}

	private function migrations(Migration ...$later): Migrator {
		$migrations = [
			new CreateOrders($this->suffix),
			new CreateItems($this->suffix),
			new AddOrderReference($this->suffix),
			...$later,
		];

		foreach (array_values($migrations) as $position => $migration) {
			$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, [
				new MigrationRegistration((string) ($position + 1), $migration),
			]);
		}

		return $this->container->get(Migrator::class);
	}

	/**
	 * Move the application's tables and ledger, then use the new WordPress prefix between operations.
	 */
	private function changePrefix(): void {
		$original = $this->source->prefix;
		$this->source->set_prefix('moved_' . $original);

		foreach ([
			'orders',
			'items',
			'history',
		] as $name) {
			$from = $this->observer->quoteSingleIdentifier($original . $this->suffix . '_' . $name);
			$to   = $this->observer->quoteSingleIdentifier($this->source->prefix . $this->suffix . '_' . $name);
			$this->observer->executeStatement('RENAME TABLE ' . $from . ' TO ' . $to);
		}

		$this->trackTables();
	}

	private function trackTables(): void {
		$this->orders  = $this->privateTable($this->suffix . '_orders');
		$this->items   = $this->privateTable($this->suffix . '_items');
		$this->history = $this->privateTable($this->suffix . '_history');
	}

	/**
	 * @return list<ForeignKeyConstraint>
	 */
	private function constraints(): array {
		return array_values($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_items')->getForeignKeys());
	}

	private function constraintName(): string {
		$name = $this->constraints()[0]->getObjectName()?->getIdentifier()->getValue();
		$this->assertNotNull($name);

		return $name;
	}

	private function addConstraint(string $name, string $actions = ''): void {
		$quoted = $this->observer->quoteSingleIdentifier($name);
		$this->observer->executeStatement("ALTER TABLE {$this->items}
			ADD CONSTRAINT $quoted FOREIGN KEY (order_id)
			REFERENCES {$this->orders} (id)$actions");
	}
}
