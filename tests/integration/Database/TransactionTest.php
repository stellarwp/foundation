<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use StellarWP\Foundation\Database\Exceptions\CommitOutcomeUnknown;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\TransactionFailed;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use Throwable;

final class TransactionTest extends DatabaseTestCase
{
	public function test_a_prefix_change_cannot_commit_even_after_the_error_is_caught(): void {
		$prefix = $this->source->prefix;

		try {
			$this->db->transactional(function () use ($prefix): void {
				$this->db->executeStatement("UPDATE {$this->table} SET name = 'Wrong'");
				$this->source->set_prefix($prefix . 'changed_');

				try {
					$this->db->fetchOne('SELECT 1');
				} catch (\RuntimeException) {
					$this->source->set_prefix($prefix);
				}
			});
			$this->fail('A caught scope failure must remain terminal.');
		} catch (\StellarWP\Foundation\Database\Exceptions\TransactionFailed) {
			$this->assertOriginal();
		} finally {
			$this->source->set_prefix($prefix);
		}
	}

	public function test_provider_shares_one_connection_and_borrows_wordpress_session(): void {
		$this->assertSame($this->db, $this->container->get(Connection::class));
		$this->assertSame($this->native($this->source), $this->db->getNativeConnection());
	}

	public function test_commit_publishes_all_work_at_once_and_returns_the_callback_result(): void {
		$result = $this->db->transactional(function (): int {
			$this->db->executeStatement('DELETE FROM ' . $this->table);
			$this->db->insert($this->table, ['id' => 2, 'name' => 'First']);
			$this->assertOriginal();
			$this->db->insert($this->table, ['id' => 3, 'name' => 'Second']);
			$this->assertOriginal();

			return 2;
		});
		$this->assertSame(2, $result);
		$this->assertSame(['First', 'Second'], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->table . ' ORDER BY id'));
	}

	public function test_manual_transactions_use_void_and_publish_only_at_outer_commit(): void {
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$this->assertSame('void', (string) (new \ReflectionMethod($this->db, $method))->getReturnType());
		}

		$this->db->beginTransaction();
		$this->db->update($this->table, ['name' => 'Outer'], ['id' => 1]);
		$this->db->beginTransaction();
		$this->db->update($this->table, ['name' => 'Nested'], ['id' => 1]);
		$this->db->commit();
		$this->assertOriginal();
		$this->db->rollBack();
		$this->assertOriginal();

		$this->db->beginTransaction();
		$this->db->update($this->table, ['name' => 'Committed'], ['id' => 1]);
		$this->db->commit();
		$this->assertSame('Committed', $this->observer->fetchOne('SELECT name FROM ' . $this->table));
		$this->assertFalse($this->db->isTransactionActive());
	}

	public function test_false_and_null_callback_results_are_successful_committed_work(): void {
		foreach ([false, null] as $expected) {
			$name   = $expected === false ? 'False result' : 'Null result';
			$result = $this->db->transactional(function () use ($expected, $name): ?bool {
				$this->db->update($this->table, ['name' => $name], ['id' => 1]);

				return $expected;
			});

			$this->assertSame($expected, $result);
			$this->assertSame($name, $this->observer->fetchOne('SELECT name FROM ' . $this->table));
			$this->assertFalse($this->db->isTransactionActive());
		}
	}

	public function test_late_failure_restores_existing_data(): void {
		$failure = new RuntimeException('Invalid later batch');

		try {
			$this->db->transactional(function () use ($failure): void {
				$this->db->executeStatement('DELETE FROM ' . $this->table);
				$this->db->insert($this->table, ['id' => 2, 'name' => 'Provisional']);

				throw $failure;
			});
		} catch (Throwable $caught) {
			$this->assertSame($failure, $caught);
		}
		$this->assertOriginal();
		$this->assertSame(42, $this->db->transactional(static fn (): int => 42));
	}

	public function test_caught_prepared_statement_failure_is_terminal(): void {
		$statement = $this->db->prepare('INSERT INTO ' . $this->table . ' (id, name) VALUES (?, ?)');

		try {
			$this->db->transactional(function () use ($statement): void {
				$this->db->update($this->table, ['name' => 'Provisional'], ['id' => 1]);

				try {
					$statement->bindValue(1, 1);
					$statement->bindValue(2, 'Duplicate');
					$statement->executeStatement();
				} catch (UniqueConstraintViolationException) {
				}
			});
			$this->fail('A caught database failure must prevent commit.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_caught_query_failure_blocks_further_queries(): void {
		try {
			$this->db->transactional(function (): void {
				try {
					$this->db->executeQuery('SELECT missing_column FROM ' . $this->table);
				} catch (Throwable) {
				}

				try {
					$this->db->insert($this->table, ['id' => 2, 'name' => 'Must not execute']);
					$this->fail('A failed transaction must reject further SQL.');
				} catch (Throwable $failure) {
					$this->assertInstanceOf(TransactionFailed::class, $failure);
				}
			});
			$this->fail('Expected terminal failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_nested_application_failure_uses_savepoint_and_outer_can_continue(): void {
		$this->db->transactional(function (): void {
			$this->db->insert($this->table, ['id' => 2, 'name' => 'Outer']);

			try {
				$this->db->transactional(function (): void {
					$this->db->insert($this->table, ['id' => 3, 'name' => 'Inner']);

					throw new RuntimeException('Skip inner work');
				});
			} catch (RuntimeException) {
			}
			$this->db->transactional(function (): void {
				$this->db->insert($this->table, ['id' => 4, 'name' => 'Nested success']);
			});
		});
		$this->assertSame([1, 2, 4], array_map('intval', $this->observer->fetchFirstColumn('SELECT id FROM ' . $this->table . ' ORDER BY id')));
	}

	public function test_outer_rollback_includes_successful_nested_work(): void {
		try {
			$this->db->transactional(function (): void {
				$this->db->transactional(function (): void {
					$this->db->insert($this->table, ['id' => 2, 'name' => 'Nested']);
				});

				throw new RuntimeException('Outer failure');
			});
		} catch (RuntimeException) {
			$this->assertOriginal();
		}
	}

	public function test_ambient_transaction_is_rejected_without_committing_or_rolling_it_back(): void {
		$native = $this->native($this->source);
		$native->begin_transaction();
		$native->query('UPDATE ' . $this->table . " SET name = 'Ambient'");

		try {
			$this->db->transactional(static function (): void {
				self::fail('Callback must not run.');
			});
		} catch (Throwable $failure) {
			$this->assertStringContainsString('Transaction characteristics', $failure->getMessage());
		}
		$this->assertOriginal();
		$result = $native->query('SELECT name FROM ' . $this->table);
		$this->assertInstanceOf(\mysqli_result::class, $result);
		$this->assertSame(['Ambient'], $result->fetch_row());
		$native->rollback();
	}

	public function test_autocommit_disabled_is_rejected(): void {
		$native = $this->native($this->source);
		$native->autocommit(false);

		try {
			$this->db->transactional(static function (): void {
				self::fail('Callback must not run.');
			});
		} catch (RuntimeException $failure) {
			$this->assertStringContainsString('autocommit', $failure->getMessage());
		} finally {
			$native->autocommit(true);
		}
	}

	public function test_lost_connection_cannot_replay_a_write_even_if_wordpress_reconnects(): void {
		try {
			@$this->db->transactional(function (): void {
				$this->db->executeStatement('DELETE FROM ' . $this->table);
				$this->killConnection();

				try {
					$this->db->insert($this->table, ['id' => 2, 'name' => 'Must not replay']);
				} catch (Throwable) {
					$this->source->db_connect(false);
				}

				try {
					$this->db->insert($this->table, ['id' => 3, 'name' => 'Must not adopt']);
				} catch (Throwable) {
				}
			});
			$this->fail('Expected terminal connection loss.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
		$this->assertSame(7, $this->db->transactional(static fn (): int => 7));
		$this->assertSame($this->native($this->source), $this->db->getNativeConnection());
	}

	public function test_lost_commit_acknowledgement_reports_uncertain_outcome(): void {
		try {
			@$this->db->transactional(function (): void {
				$this->db->update($this->table, ['name' => 'Lost'], ['id' => 1]);
				$this->killConnection();
			});
			$this->fail('Expected uncertain commit.');
		} catch (DatabaseException $failure) {
			$this->assertInstanceOf(CommitOutcomeUnknown::class, $failure);
			$this->assertOriginal();
		}
	}

	public function test_cleanup_failure_preserves_the_original_business_exception(): void {
		$failure = new RuntimeException('Original business failure');

		try {
			$this->db->transactional(function () use ($failure): void {
				$this->native($this->source)->close();

				throw $failure;
			});
		} catch (Throwable $caught) {
			$this->assertSame($failure, $caught);
		}
	}

	public function test_site_change_prevents_commit_and_restoration_does_not_erase_failure(): void {
		$otherSite = $this->factory()->blog->create();
		$this->assertIsInt($otherSite);

		try {
			$this->db->transactional(function () use ($otherSite): void {
				$this->db->update($this->table, ['name' => 'Wrong scope'], ['id' => 1]);
				switch_to_blog($otherSite);

				try {
					$this->db->executeQuery('SELECT 1');
				} catch (Throwable) {
				} finally {
					restore_current_blog();
				}
			});
			$this->fail('Expected terminal scope failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_caught_nested_database_failure_still_aborts_outer_work(): void {
		try {
			$this->db->transactional(function (): void {
				$this->db->update($this->table, ['name' => 'Provisional'], ['id' => 1]);

				try {
					$this->db->transactional(function (): void {
						$this->db->insert($this->table, ['id' => 1, 'name' => 'Duplicate']);
					});
				} catch (UniqueConstraintViolationException) {
				}
			});
			$this->fail('Caught nested database failure must abort the outer transaction.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_a_business_exception_takes_precedence_over_a_caught_database_failure(): void {
		$business = new RuntimeException('Application failure');

		try {
			$this->db->transactional(function () use ($business): void {
				try {
					$this->db->executeQuery('SELECT missing_column FROM ' . $this->table);
				} catch (Throwable) {
				}

				throw $business;
			});
		} catch (Throwable $failure) {
			$this->assertSame($business, $failure);
		}
		$this->assertOriginal();
	}

	public function test_replacing_a_live_wordpress_session_prevents_commit(): void {
		try {
			$this->db->transactional(function (): void {
				$this->db->executeStatement('DELETE FROM ' . $this->table);
				$this->source->db_connect(false);
			});
			$this->fail('Expected connection replacement to abort the operation.');
		} catch (RuntimeException $failure) {
			$this->assertStringContainsString('connection changed', $failure->getMessage());
			$this->assertOriginal();
		}
		$this->assertSame(9, $this->db->transactional(static fn (): int => 9));
	}

	public function test_native_logging_middleware_can_wrap_the_wordpress_driver(): void {
		$logger  = new \Monolog\Logger('doctrine-evaluation');
		$handler = new \Monolog\Handler\TestHandler();
		$logger->pushHandler($handler);
		$db = $this->withLogging($logger);
		$this->assertSame(1, (int) $db->transactional(static fn (Connection $db): mixed => $db->fetchOne('SELECT 1')));
		$this->assertTrue($handler->hasDebugThatContains('Committing transaction'));
	}

	public function test_connection_loss_before_begin_rejects_the_callback(): void {
		$logger = new \Monolog\Logger('doctrine-evaluation');
		$logger->pushHandler(new \Monolog\Handler\TestHandler());
		$logger->pushProcessor(function (array $record): array {
			if ($record['message'] === 'Beginning transaction') {
				$this->killConnection();
			}

			return $record;
		});
		$db     = $this->withLogging($logger);
		$called = false;

		try {
			@$db->transactional(static function () use (&$called): void {
				$called = true;
			});
			$this->fail('Expected begin failure.');
		} catch (RuntimeException $failure) {
			$this->assertStringContainsString('Unable to start', $failure->getMessage());
		}
		$this->assertFalse($called);
		$this->assertOriginal();
	}

	public function test_caught_savepoint_release_failure_remains_terminal(): void {
		$logger = new \Monolog\Logger('doctrine-evaluation');
		$logger->pushHandler(new \Monolog\Handler\TestHandler());
		$logger->pushProcessor(function (array $record): array {
			if (str_starts_with($record['context']['sql'] ?? '', 'RELEASE SAVEPOINT')) {
				$this->killConnection();
			}

			return $record;
		});
		$db = $this->withLogging($logger);

		try {
			@$db->transactional(function () use ($db): void {
				try {
					$db->transactional(function () use ($db): void {
						$db->update($this->table, ['name' => 'Nested'], ['id' => 1]);
					});
				} catch (Throwable) {
				}
			});
			$this->fail('Expected terminal savepoint failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_stale_prepared_statement_cannot_write_to_an_old_session(): void {
		$statement = $this->db->prepare('UPDATE ' . $this->table . ' SET name = ?');
		$this->source->db_connect(false);

		try {
			$this->db->transactional(static function () use ($statement): void {
				try {
					$statement->bindValue(1, 'Stale');
					$statement->executeStatement();
				} catch (Throwable) {
				}
			});
			$this->fail('Expected stale statement failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_a_closed_wordpress_source_is_rejected(): void {
		$this->source->close();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('WordPress must have an open mysqli connection.');
		$this->db->transactional(static function (): void {
			self::fail('Must not invoke work.');
		});
	}

	public function test_native_manual_rollback_remains_available(): void {
		$this->db->beginTransaction();
		$this->db->update($this->table, ['name' => 'Provisional'], ['id' => 1]);
		$this->db->rollBack();
		$this->assertOriginal();
		$this->db->beginTransaction();
		$this->killConnection();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The database did not acknowledge rollback.');
		@$this->db->rollBack();
	}

	private function withLogging(\Psr\Log\LoggerInterface $logger): Connection {
		$configuration = new \Doctrine\DBAL\Configuration();
		$configuration->setSchemaManagerFactory(new \Doctrine\DBAL\Schema\DefaultSchemaManagerFactory());
		$configuration->setMiddlewares([
			new \StellarWP\Foundation\Database\Connection\WordPressMiddleware($this->container->get(\StellarWP\Foundation\Database\Connection\WordPressSession::class)),
			new \Doctrine\DBAL\Logging\Middleware($logger),
		]);
		$db = \Doctrine\DBAL\DriverManager::getConnection([
			'driver'       => 'mysqli',
			'wrapperClass' => \StellarWP\Foundation\Database\Connection\WordPressConnection::class,
		], $configuration);

		return $db;
	}
}
