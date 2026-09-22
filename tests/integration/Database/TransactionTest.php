<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use StellarWP\Foundation\Database\Exceptions\CommitOutcomeUnknown;
use StellarWP\Foundation\Database\Exceptions\TransactionFailed;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use Throwable;

final class TransactionTest extends DatabaseTestCase
{
	public function test_a_prefix_change_cannot_commit_even_after_the_error_is_caught(): void {
		$prefix = $this->source->prefix;

		try {
			$this->connection->transactional(function () use ($prefix): void {
				$this->connection->executeStatement("UPDATE {$this->table} SET name = 'Wrong'");
				$this->source->set_prefix($prefix . 'changed_');

				try {
					$this->connection->fetchOne('SELECT 1');
				} catch (\RuntimeException) {
					$this->source->set_prefix($prefix);
				}
			});
			self::fail('A caught scope failure must remain terminal.');
		} catch (\StellarWP\Foundation\Database\Exceptions\TransactionFailed) {
			$this->assertOriginal();
		} finally {
			$this->source->set_prefix($prefix);
		}
	}

	public function test_provider_shares_one_connection_and_borrows_wordpress_session(): void {
		self::assertSame($this->connection, $this->container->get(Connection::class));
		self::assertSame($this->native($this->source), $this->connection->getNativeConnection());
	}

	public function test_commit_publishes_all_work_at_once_and_returns_the_callback_result(): void {
		$result = $this->connection->transactional(function (): int {
			$this->connection->executeStatement('DELETE FROM ' . $this->table);
			$this->connection->insert($this->table, ['id' => 2, 'name' => 'First']);
			$this->assertOriginal();
			$this->connection->insert($this->table, ['id' => 3, 'name' => 'Second']);
			$this->assertOriginal();

			return 2;
		});
		self::assertSame(2, $result);
		self::assertSame(['First', 'Second'], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->table . ' ORDER BY id'));
	}

	public function test_manual_transactions_use_void_and_publish_only_at_outer_commit(): void {
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			self::assertSame('void', (string) (new \ReflectionMethod($this->connection, $method))->getReturnType());
		}

		$this->connection->beginTransaction();
		$this->connection->update($this->table, ['name' => 'Outer'], ['id' => 1]);
		$this->connection->beginTransaction();
		$this->connection->update($this->table, ['name' => 'Nested'], ['id' => 1]);
		$this->connection->commit();
		$this->assertOriginal();
		$this->connection->rollBack();
		$this->assertOriginal();

		$this->connection->beginTransaction();
		$this->connection->update($this->table, ['name' => 'Committed'], ['id' => 1]);
		$this->connection->commit();
		self::assertSame('Committed', $this->observer->fetchOne('SELECT name FROM ' . $this->table));
		self::assertFalse($this->connection->isTransactionActive());
	}

	public function test_false_and_null_callback_results_are_successful_committed_work(): void {
		foreach ([false, null] as $expected) {
			$name   = $expected === false ? 'False result' : 'Null result';
			$result = $this->connection->transactional(function () use ($expected, $name): ?bool {
				$this->connection->update($this->table, ['name' => $name], ['id' => 1]);

				return $expected;
			});

			self::assertSame($expected, $result);
			self::assertSame($name, $this->observer->fetchOne('SELECT name FROM ' . $this->table));
			self::assertFalse($this->connection->isTransactionActive());
		}
	}

	public function test_late_failure_restores_existing_data(): void {
		$failure = new RuntimeException('Invalid later batch');

		try {
			$this->connection->transactional(function () use ($failure): void {
				$this->connection->executeStatement('DELETE FROM ' . $this->table);
				$this->connection->insert($this->table, ['id' => 2, 'name' => 'Provisional']);

				throw $failure;
			});
		} catch (Throwable $caught) {
			self::assertSame($failure, $caught);
		}
		$this->assertOriginal();
		self::assertSame(42, $this->connection->transactional(static fn (): int => 42));
	}

	public function test_caught_prepared_statement_failure_is_terminal(): void {
		$statement = $this->connection->prepare('INSERT INTO ' . $this->table . ' (id, name) VALUES (?, ?)');

		try {
			$this->connection->transactional(function () use ($statement): void {
				$this->connection->update($this->table, ['name' => 'Provisional'], ['id' => 1]);

				try {
					$statement->bindValue(1, 1);
					$statement->bindValue(2, 'Duplicate');
					$statement->executeStatement();
				} catch (UniqueConstraintViolationException) {
				}
			});
			self::fail('A caught database failure must prevent commit.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_caught_query_failure_blocks_further_queries(): void {
		try {
			$this->connection->transactional(function (): void {
				try {
					$this->connection->executeQuery('SELECT missing_column FROM ' . $this->table);
				} catch (Throwable) {
				}

				try {
					$this->connection->insert($this->table, ['id' => 2, 'name' => 'Must not execute']);
					self::fail('A failed transaction must reject further SQL.');
				} catch (Throwable $failure) {
					self::assertInstanceOf(TransactionFailed::class, $failure);
				}
			});
			self::fail('Expected terminal failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_nested_application_failure_uses_savepoint_and_outer_can_continue(): void {
		$this->connection->transactional(function (): void {
			$this->connection->insert($this->table, ['id' => 2, 'name' => 'Outer']);

			try {
				$this->connection->transactional(function (): void {
					$this->connection->insert($this->table, ['id' => 3, 'name' => 'Inner']);

					throw new RuntimeException('Skip inner work');
				});
			} catch (RuntimeException) {
			}
			$this->connection->transactional(function (): void {
				$this->connection->insert($this->table, ['id' => 4, 'name' => 'Nested success']);
			});
		});
		self::assertSame([1, 2, 4], array_map('intval', $this->observer->fetchFirstColumn('SELECT id FROM ' . $this->table . ' ORDER BY id')));
	}

	public function test_outer_rollback_includes_successful_nested_work(): void {
		try {
			$this->connection->transactional(function (): void {
				$this->connection->transactional(function (): void {
					$this->connection->insert($this->table, ['id' => 2, 'name' => 'Nested']);
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
			$this->connection->transactional(static function (): void {
				self::fail('Callback must not run.');
			});
		} catch (Throwable $failure) {
			self::assertStringContainsString('Transaction characteristics', $failure->getMessage());
		}
		$this->assertOriginal();
		$result = $native->query('SELECT name FROM ' . $this->table);
		self::assertInstanceOf(\mysqli_result::class, $result);
		self::assertSame(['Ambient'], $result->fetch_row());
		$native->rollback();
	}

	public function test_autocommit_disabled_is_rejected(): void {
		$native = $this->native($this->source);
		$native->autocommit(false);

		try {
			$this->connection->transactional(static function (): void {
				self::fail('Callback must not run.');
			});
		} catch (RuntimeException $failure) {
			self::assertStringContainsString('autocommit', $failure->getMessage());
		} finally {
			$native->autocommit(true);
		}
	}

	public function test_lost_connection_cannot_replay_a_write_even_if_wordpress_reconnects(): void {
		try {
			@$this->connection->transactional(function (): void {
				$this->connection->executeStatement('DELETE FROM ' . $this->table);
				$this->killConnection();

				try {
					$this->connection->insert($this->table, ['id' => 2, 'name' => 'Must not replay']);
				} catch (Throwable) {
					$this->source->db_connect(false);
				}

				try {
					$this->connection->insert($this->table, ['id' => 3, 'name' => 'Must not adopt']);
				} catch (Throwable) {
				}
			});
			self::fail('Expected terminal connection loss.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
		self::assertSame(7, $this->connection->transactional(static fn (): int => 7));
		self::assertSame($this->native($this->source), $this->connection->getNativeConnection());
	}

	public function test_lost_commit_acknowledgement_reports_uncertain_outcome(): void {
		try {
			@$this->connection->transactional(function (): void {
				$this->connection->update($this->table, ['name' => 'Lost'], ['id' => 1]);
				$this->killConnection();
			});
			self::fail('Expected uncertain commit.');
		} catch (CommitOutcomeUnknown) {
			$this->assertOriginal();
		}
	}

	public function test_cleanup_failure_preserves_the_original_business_exception(): void {
		$failure = new RuntimeException('Original business failure');

		try {
			$this->connection->transactional(function () use ($failure): void {
				$this->native($this->source)->close();

				throw $failure;
			});
		} catch (Throwable $caught) {
			self::assertSame($failure, $caught);
		}
	}

	public function test_site_change_prevents_commit_and_restoration_does_not_erase_failure(): void {
		$otherSite = self::factory()->blog->create();
		self::assertIsInt($otherSite);

		try {
			$this->connection->transactional(function () use ($otherSite): void {
				$this->connection->update($this->table, ['name' => 'Wrong scope'], ['id' => 1]);
				switch_to_blog($otherSite);

				try {
					$this->connection->executeQuery('SELECT 1');
				} catch (Throwable) {
				} finally {
					restore_current_blog();
				}
			});
			self::fail('Expected terminal scope failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_caught_nested_database_failure_still_aborts_outer_work(): void {
		try {
			$this->connection->transactional(function (): void {
				$this->connection->update($this->table, ['name' => 'Provisional'], ['id' => 1]);

				try {
					$this->connection->transactional(function (): void {
						$this->connection->insert($this->table, ['id' => 1, 'name' => 'Duplicate']);
					});
				} catch (UniqueConstraintViolationException) {
				}
			});
			self::fail('Caught nested database failure must abort the outer transaction.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_a_business_exception_takes_precedence_over_a_caught_database_failure(): void {
		$business = new RuntimeException('Application failure');

		try {
			$this->connection->transactional(function () use ($business): void {
				try {
					$this->connection->executeQuery('SELECT missing_column FROM ' . $this->table);
				} catch (Throwable) {
				}

				throw $business;
			});
		} catch (Throwable $failure) {
			self::assertSame($business, $failure);
		}
		$this->assertOriginal();
	}

	public function test_replacing_a_live_wordpress_session_prevents_commit(): void {
		try {
			$this->connection->transactional(function (): void {
				$this->connection->executeStatement('DELETE FROM ' . $this->table);
				$this->source->db_connect(false);
			});
			self::fail('Expected connection replacement to abort the operation.');
		} catch (RuntimeException $failure) {
			self::assertStringContainsString('connection changed', $failure->getMessage());
			$this->assertOriginal();
		}
		self::assertSame(9, $this->connection->transactional(static fn (): int => 9));
	}

	public function test_native_logging_middleware_can_wrap_the_wordpress_driver(): void {
		$logger  = new \Monolog\Logger('doctrine-evaluation');
		$handler = new \Monolog\Handler\TestHandler();
		$logger->pushHandler($handler);
		$connection = $this->withLogging($logger);
		self::assertSame(1, (int) $connection->transactional(static fn (Connection $connection): mixed => $connection->fetchOne('SELECT 1')));
		self::assertTrue($handler->hasDebugThatContains('Committing transaction'));
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
		$connection = $this->withLogging($logger);
		$called     = false;

		try {
			@$connection->transactional(static function () use (&$called): void {
				$called = true;
			});
			self::fail('Expected begin failure.');
		} catch (RuntimeException $failure) {
			self::assertStringContainsString('Unable to start', $failure->getMessage());
		}
		self::assertFalse($called);
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
		$connection = $this->withLogging($logger);

		try {
			@$connection->transactional(function () use ($connection): void {
				try {
					$connection->transactional(function () use ($connection): void {
						$connection->update($this->table, ['name' => 'Nested'], ['id' => 1]);
					});
				} catch (Throwable) {
				}
			});
			self::fail('Expected terminal savepoint failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_stale_prepared_statement_cannot_write_to_an_old_session(): void {
		$statement = $this->connection->prepare('UPDATE ' . $this->table . ' SET name = ?');
		$this->source->db_connect(false);

		try {
			$this->connection->transactional(static function () use ($statement): void {
				try {
					$statement->bindValue(1, 'Stale');
					$statement->executeStatement();
				} catch (Throwable) {
				}
			});
			self::fail('Expected stale statement failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	public function test_a_closed_wordpress_source_is_rejected(): void {
		$this->source->close();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('WordPress must have an open mysqli connection.');
		$this->connection->transactional(static function (): void {
			self::fail('Must not invoke work.');
		});
	}

	public function test_native_manual_rollback_remains_available(): void {
		$this->connection->beginTransaction();
		$this->connection->update($this->table, ['name' => 'Provisional'], ['id' => 1]);
		$this->connection->rollBack();
		$this->assertOriginal();
		$this->connection->beginTransaction();
		$this->killConnection();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The database did not acknowledge rollback.');
		@$this->connection->rollBack();
	}

	private function withLogging(\Psr\Log\LoggerInterface $logger): Connection {
		$configuration = new \Doctrine\DBAL\Configuration();
		$configuration->setSchemaManagerFactory(new \Doctrine\DBAL\Schema\DefaultSchemaManagerFactory());
		$configuration->setMiddlewares([
			new \StellarWP\Foundation\Database\Connection\WordPressMiddleware($this->container->get(\StellarWP\Foundation\Database\Connection\WordPressSession::class)),
			new \Doctrine\DBAL\Logging\Middleware($logger),
		]);
		$connection = \Doctrine\DBAL\DriverManager::getConnection([
			'driver'       => 'mysqli',
			'wrapperClass' => \StellarWP\Foundation\Database\Connection\WordPressConnection::class,
		], $configuration);

		return $connection;
	}
}
