<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Exceptions\TransactionFailed;
use StellarWP\Foundation\Database\Query\Database;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Log\CallbackLogger;
use wpdb;

/**
 * Contribute native Doctrine middleware without replacing Foundation's connection wiring.
 */
final class MiddlewareTest extends DatabaseTestCase
{
	protected function setUp(): void {
		parent::setUp();
		$this->container = (new ContainerFactory())->create(new ArrayConfiguration([]));
		$this->container->register(DatabaseProvider::class);
		$this->container->singleton(wpdb::class, $this->source);
	}

	/**
	 * Resolve middleware once with the shared connection, without opening it eagerly.
	 */
	public function test_contributions_are_lazy_and_share_the_application_connection(): void {
		$resolutions = 0;
		$observed    = [];
		$logger      = new CallbackLogger(static function (string $message, array $context) use (&$observed): void {
			if (isset($context['sql'])) {
				$observed[] = $context['sql'];
			}
		});
		$this->container->mergeArrayVar(DatabaseProvider::MIDDLEWARE, static function () use (&$resolutions, $logger): array {
			$resolutions++;

			return [
				new Middleware($logger),
			];
		});
		$this->assertSame(0, $resolutions);
		$db = $this->container->get(Connection::class);
		$this->assertSame(1, $resolutions);
		$this->assertFalse($db->isConnected());
		$this->assertSame($db, $this->container->get(Connection::class));
		$this->assertSame(1, $resolutions);

		$this->assertSame(1, $this->container->get(Database::class)->table($this->suffix)->count());
		$this->assertNotEmpty($observed);
		$this->assertSame($this->native($this->source)->thread_id, (int) $db->fetchOne('SELECT CONNECTION_ID()'));
	}

	/**
	 * Later contributions wrap earlier ones for direct and repeatedly executed prepared queries.
	 */
	public function test_multiple_contributions_observe_queries_in_decorator_order(): void {
		$events = [];

		foreach ([
			'first',
			'second',
		] as $name) {
			$logger = new CallbackLogger(static function (string $message, array $context) use (&$events, $name): void {
				if (isset($context['sql'])) {
					$events[] = $name . ': ' . $context['sql'];
				}
			});
			$this->container->mergeArrayVar(DatabaseProvider::MIDDLEWARE, static fn (): array => [
				new Middleware($logger),
			]);
		}

		$db = $this->container->get(Connection::class);
		$this->assertSame(1, (int) $db->fetchOne('SELECT 1'));
		$statement = $db->prepare('SELECT ?');
		$statement->bindValue(1, 'first execution');
		$this->assertSame('first execution', $statement->executeQuery()->fetchOne());
		$statement->bindValue(1, 'second execution');
		$this->assertSame('second execution', $statement->executeQuery()->fetchOne());
		$update = "UPDATE {$this->table}
			SET name = 'Changed'";
		$db->executeStatement($update);
		$this->assertSame([
			'second: SELECT 1',
			'first: SELECT 1',
			'second: SELECT ?',
			'first: SELECT ?',
			'second: SELECT ?',
			'first: SELECT ?',
			'second: ' . $update,
			'first: ' . $update,
		], $events);
		$this->assertSame('Changed', $this->observer->fetchOne("SELECT name
			FROM {$this->table}"));
	}

	/**
	 * Decorating execution does not permit a caught database failure to commit earlier work.
	 */
	public function test_caught_database_errors_remain_terminal_with_contributed_middleware(): void {
		$this->container->mergeArrayVar(DatabaseProvider::MIDDLEWARE, static fn (): array => [
			new Middleware(new \Psr\Log\NullLogger()),
		]);
		$db = $this->container->get(Connection::class);

		try {
			$db->transactional(function () use ($db): void {
				$db->executeStatement("UPDATE {$this->table}
					SET name = 'Provisional'");

				try {
					$db->insert($this->table, [
						'id'   => 1,
						'name' => 'Duplicate',
					]);
				} catch (UniqueConstraintViolationException) {
				}
			});
			$this->fail('A caught database failure must prevent commit.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}
}
