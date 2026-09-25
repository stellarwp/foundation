<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use StellarWP\Foundation\Migrations\Contracts\Migration;
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

	public function test_logical_name_changes_still_replace_the_constraint(): void {
		$migrator = $this->migrations(new RenameOrderReference($this->suffix));
		$migrator->migrate('3');
		$original = $this->constraintName();
		$this->changePrefix();
		$steps = $migrator->migrate();
		$this->assertNotEmpty($steps[0]->sql);
		$this->assertNotSame($original, $this->constraintName());
		$this->assertCount(1, $this->constraints());
		$migrator->rollback();
		$this->assertCount(1, $this->constraints());
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
		$this->orders = $this->privateTable($this->suffix . '_orders');
		$this->items  = $this->privateTable($this->suffix . '_items');
		$this->privateTable($this->suffix . '_history');
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
