<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Closure;
use Doctrine\DBAL\Connection;
use RuntimeException;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Connection\WordPressSession;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Migration\Contracts\MigratesData;
use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Database\Migration\History;
use StellarWP\Foundation\Database\Migration\MigrationCollection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;
use StellarWP\Foundation\Database\Migration\Schema\SchemaPlanner;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\LostAdvisoryLockReply;
use Throwable;
use wpdb;

final class AdvisoryMigrationTest extends DatabaseTestCase
{
	private Container $contender;
	private string $ledger;
	private string $resource;

	protected function setUp(): void {
		parent::setUp();
		$this->observerSource->set_prefix($this->source->prefix);
		$this->observerSource->set_blog_id(get_current_blog_id());
		$this->contender = (new ContainerFactory())->create(new ArrayConfiguration([]));
		$this->contender->register(DatabaseProvider::class);
		$this->contender->singleton(wpdb::class, $this->observerSource);
		$this->resource = $this->source->prefix . $this->suffix . '_ledger';
		$this->ledger   = $this->privateTable($this->suffix . '_ledger');
	}

	/**
	 * @param Closure(Connection): void $operation
	 */
	private function runner(Container $container, Closure $operation, ?string $resource = null): Migrator {
		$db    = $container->get(Connection::class);
		$scope = $container->get(DatabaseScope::class);
		$names = $container->get(TableNameResolver::class);
		$resource ??= $scope->resolveTableName($this->suffix . '_ledger');
		$table     = new MigrationTable(substr($resource, strlen($scope->resolveTableName(''))), $db, $names);
		$migration = new class($operation) implements MigratesData, Migration {
			/** @param Closure(Connection): void $operation */
			public function __construct(private readonly Closure $operation) {
			}
			public function id(): string {
				return '20260922000001';
			}
			public function up(Blueprint $schema): void {
			}
			public function down(Blueprint $schema): void {
			}
			public function migrate(Connection $db): void {
				($this->operation)($db);
			}
		};
		$migrations = new MigrationCollection([$migration]);

		return new Migrator($db, $container->get(WordPressSession::class), $scope,
			new History($db, $table), new SchemaPlanner($db, $names, $migrations), $migrations);
	}

	private function lockName(?string $resource = null): string {
		return hash('sha256', constant('DB_NAME') . "\0" . ($resource ?? $this->resource));
	}

	private function assertLockAvailable(?string $resource = null): void {
		self::assertSame(1, (int) $this->observer->fetchOne('SELECT IS_FREE_LOCK(?)', [$this->lockName($resource)]));
	}

	public function test_an_unclosed_data_transaction_is_rolled_back_without_recording_success(): void {
		try {
			$this->runner($this->container, function (Connection $db): void {
				$db->beginTransaction();
				$db->executeStatement("UPDATE {$this->table} SET name = 'Uncommitted'");
			})->migrate();
			self::fail('A callback cannot return success with an open transaction.');
		} catch (MigrationInterrupted $failure) {
			self::assertStringContainsString('left a transaction open', $failure->getMessage());
			$this->assertOriginal();
			self::assertFalse($this->db->isTransactionActive());
			self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->ledger));
			$this->assertLockAvailable();
		}
	}

	public function test_competing_runner_cannot_plan_until_ddl_and_history_finish(): void {
		$second = $this->runner($this->contender, function (Connection $db): void {
			$db->executeStatement("UPDATE {$this->table} SET name = CONCAT(name, '|duplicate')");
		});
		$first = $this->runner($this->container, function (Connection $db) use ($second): void {
			$db->executeStatement("ALTER TABLE {$this->table} ADD migrated INT NOT NULL DEFAULT 1");
			$db->executeStatement("UPDATE {$this->table} SET name = CONCAT(name, '|once')");

			try {
				$second->migrate();
				self::fail('The second session must not run before history is recorded.');
			} catch (MigrationAlreadyRunning) {
				self::assertSame(0, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
			}
		});
		$first->migrate();
		self::assertSame([], $second->migrate());
		self::assertSame('Original|once', $this->observer->fetchOne("SELECT name FROM {$this->table}"));
		self::assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
		$this->assertLockAvailable();
	}

	public function test_contention_does_not_create_the_history_table(): void {
		$name = $this->lockName();
		self::assertSame(1, (int) $this->observer->fetchOne('SELECT GET_LOCK(?, 0)', [$name]));

		try {
			$this->runner($this->container, static function (): void {
			})->migrate();
			self::fail('Expected contention.');
		} catch (MigrationAlreadyRunning) {
			self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->resource]));
		} finally {
			$this->observer->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
		}
	}

	public function test_another_application_can_migrate_while_the_first_is_locked(): void {
		$other = $this->resource . '_other';
		$this->privateTable($this->suffix . '_ledger_other');
		$second = $this->runner($this->contender, static function (): void {
		}, $other);
		$this->runner($this->container, static function () use ($second): void {
			self::assertCount(1, $second->migrate());
		})->migrate();
		$this->assertLockAvailable();
		$this->assertLockAvailable($other);
	}

	public function test_different_site_prefixes_do_not_share_a_lock(): void {
		$otherPrefix = $this->source->prefix . $this->suffix . '_site2_';
		$this->observerSource->set_prefix($otherPrefix);
		$other          = $otherPrefix . $this->suffix . '_ledger';
		$this->tables[] = $this->observer->getDatabasePlatform()->quoteSingleIdentifier($other);
		$second         = $this->runner($this->contender, static function (): void {
		}, $other);
		$this->runner($this->container, static function () use ($second): void {
			self::assertCount(1, $second->migrate());
		})->migrate();
		$this->assertLockAvailable($other);
	}

	public function test_lock_survives_commits_and_rollbacks_in_a_migration(): void {
		$this->runner($this->container, function (Connection $db): void {
			$db->transactional(function (Connection $db): void {
				$db->executeStatement("UPDATE {$this->table} SET name = 'Committed'");
			});
			self::assertSame(0, (int) $this->observer->fetchOne('SELECT IS_FREE_LOCK(?)', [$this->lockName()]));

			try {
				$db->transactional(static function (): void {
					throw new RuntimeException('Discard this inner transaction');
				});
			} catch (RuntimeException) {
				self::assertSame(0, (int) $this->observer->fetchOne('SELECT IS_FREE_LOCK(?)', [$this->lockName()]));
			}
		})->migrate();
		$this->assertLockAvailable();
	}

	public function test_killed_session_cannot_record_success_after_a_caught_error(): void {
		try {
			$this->runner($this->container, function (Connection $db): void {
				$this->killConnection();

				try {
					$db->executeStatement("UPDATE {$this->table} SET name = 'Wrong'");
				} catch (Throwable) {
					// Simulate migration code that catches and ignores a lost connection.
				}
			})->migrate();
			self::fail('A caught connection loss must remain terminal.');
		} catch (MigrationInterrupted) {
			$this->assertOriginal();
			self::assertSame(0, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
			$this->assertLockAvailable();
		}
		$this->runner($this->contender, static function (): void {
		})->migrate();
		self::assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
	}

	public function test_business_exception_survives_lost_connection_during_cleanup(): void {
		$expected = new RuntimeException('Business failure');

		try {
			$this->runner($this->container, function () use ($expected): void {
				$this->killConnection();

				throw $expected;
			})->migrate();
			self::fail('Expected the original exception.');
		} catch (Throwable $failure) {
			self::assertSame($expected, $failure);
			$this->assertLockAvailable();
		}
	}

	public function test_site_change_blocks_the_ledger_and_releases_only_the_original_lock(): void {
		$site = self::factory()->blog->create();
		self::assertIsInt($site);

		try {
			$this->runner($this->container, static function () use ($site): void {
				switch_to_blog($site);
			})->migrate();
			self::fail('A migration cannot change WordPress sites.');
		} catch (MigrationInterrupted) {
			self::assertSame(0, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
			$this->assertLockAvailable();
		} finally {
			restore_current_blog();
		}
	}

	public function test_manual_lock_release_is_detected_before_more_sql(): void {
		try {
			$this->runner($this->container, function (): void {
				$this->native($this->source)->query('SELECT RELEASE_ALL_LOCKS()');
			})->migrate();
			self::fail('A normal return must not hide lost ownership.');
		} catch (MigrationInterrupted) {
			self::assertSame(0, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
			$this->assertLockAvailable();
		}
	}

	public function test_replacing_the_wordpress_connection_is_terminal_even_if_restored(): void {
		$native = $this->native($this->source);

		try {
			$this->runner($this->container, function (Connection $db) use ($native): void {
				$this->source->__set('dbh', $this->native($this->observerSource));

				try {
					$db->executeStatement("UPDATE {$this->table} SET name = 'Wrong'");
				} catch (Throwable $failure) {
					self::assertInstanceOf(MigrationInterrupted::class, $failure);
					$this->source->__set('dbh', $native);
				}
			})->migrate();
			self::fail('Restoring the connection must not reset terminal failure.');
		} catch (MigrationInterrupted) {
			$this->assertOriginal();
			self::assertSame(0, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
			$this->assertLockAvailable();
		} finally {
			$this->source->__set('dbh', $native);
		}
	}

	public function test_caught_sql_failure_remains_terminal_after_inner_transaction_cleanup(): void {
		try {
			$this->runner($this->container, function (Connection $db): void {
				try {
					$db->transactional(function (Connection $db): void {
						$db->insert(trim($this->table, '`'), ['id' => 1, 'name' => 'Duplicate']);
					});
				} catch (Throwable) {
					// The transaction ends, but this migration must remain failed.
				}
			})->migrate();
			self::fail('A caught SQL failure must prevent the history write.');
		} catch (MigrationInterrupted) {
			self::assertSame(0, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->ledger}"));
			$this->assertOriginal();
			$this->assertLockAvailable();
		}
	}

	public function test_migration_failure_releases_lock_for_a_fresh_retry(): void {
		try {
			$this->runner($this->container, static function (): void {
				throw new RuntimeException('Migration failed');
			})->migrate();
			self::fail('Expected failure.');
		} catch (RuntimeException $failure) {
			self::assertSame('Migration failed', $failure->getMessage());
			$this->assertLockAvailable();
		}
		self::assertCount(1, $this->runner($this->container, static function (): void {
		})->migrate());
	}

	public function test_ambient_transaction_is_rejected_without_committing_its_writes(): void {
		$native = $this->native($this->source);
		$native->begin_transaction();
		$native->query("UPDATE {$this->table} SET name = 'Uncommitted'");

		try {
			$this->runner($this->container, static function (): void {
			})->migrate();
			self::fail('Expected an ambient transaction rejection.');
		} catch (Throwable) {
			$this->assertOriginal();
			self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->resource]));
			$this->assertLockAvailable();
		} finally {
			$native->rollback();
		}
	}

	public function test_disabled_autocommit_is_rejected(): void {
		$native = $this->native($this->source);
		$native->autocommit(false);

		try {
			$this->runner($this->container, static function (): void {
			})->migrate();
			self::fail('Expected disabled autocommit rejection.');
		} catch (RuntimeException $failure) {
			self::assertSame('Migrations require autocommit to be enabled.', $failure->getMessage());
			$this->assertLockAvailable();
		} finally {
			$native->autocommit(true);
		}
	}

	public function test_nested_migration_run_is_rejected(): void {
		$this->runner($this->container, function (): void {
			try {
				$this->runner($this->container, static function (): void {
				})->migrate();
				self::fail('Expected nested migration rejection.');
			} catch (RuntimeException $failure) {
				self::assertSame('Start migrations outside an existing transaction or migration run.', $failure->getMessage());
			}
		})->migrate();
		$this->assertLockAvailable();
	}

	public function test_composed_runner_resolves_the_current_prefix_between_operations(): void {
		$runner = $this->runner($this->container, static function (): void {
		});
		$this->source->set_prefix($this->source->prefix . 'changed_');
		$ledger = $this->privateTable($this->suffix . '_ledger');
		self::assertCount(1, $runner->migrate());
		self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $ledger));
		self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->resource]));
	}

	public function test_missing_acquisition_acknowledgement_discards_the_session_and_its_lock(): void {
		$this->native($this->source)->close();
		$this->source->__set('dbh', new LostAdvisoryLockReply(constant('DB_HOST'), constant('DB_USER'), constant('DB_PASSWORD'), constant('DB_NAME')));

		try {
			$this->runner($this->container, static function (): void {
			})->migrate();
			self::fail('A lost acquisition reply cannot permit execution.');
		} catch (RuntimeException $failure) {
			self::assertSame('Lost advisory lock acquisition reply', $failure->getMessage());
			self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->resource]));
			$this->assertLockAvailable();
		}
	}

	public function test_a_closed_handle_during_failure_preserves_the_business_exception(): void {
		$expected = new RuntimeException('Failed after closing the connection');

		try {
			$this->runner($this->container, function () use ($expected): void {
				$this->native($this->source)->close();

				throw $expected;
			})->migrate();
			self::fail('Expected the business exception.');
		} catch (Throwable $failure) {
			self::assertSame($expected, $failure);
			$this->assertLockAvailable();
		}
	}
}
