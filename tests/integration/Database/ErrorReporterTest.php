<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use mysqli_driver;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use StellarWP\Foundation\Database\Contracts\AdvisorySession;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

final class ErrorReporterTest extends DatabaseTestCase
{
	/**
	 * @return iterable<string, array{int}>
	 */
	public static function modes(): iterable {
		yield 'WordPress reporting disabled' => [
			MYSQLI_REPORT_OFF,
		];

		yield 'caller reporting errors without exceptions' => [
			MYSQLI_REPORT_ERROR,
		];
	}

	/**
	 * @dataProvider modes
	 */
	#[DataProvider('modes')]
	public function test_driver_failures_keep_doctrine_exceptions_and_restore_reporting(int $mode): void {
		$statement = $this->db->prepare('INSERT INTO ' . $this->table . ' (id, name) VALUES (?, ?)');
		$statement->bindValue(1, 1);
		$statement->bindValue(2, 'Duplicate');
		$driver              = new mysqli_driver();
		$original            = $driver->report_mode;
		$driver->report_mode = $mode;

		try {
			$this->assertSame('Original', $this->db->fetchOne('SELECT name FROM ' . $this->table));
			$this->assertThat((new mysqli_driver())->report_mode, $this->identicalTo($mode));

			foreach ([
				fn () => $this->db->prepare('SELECT missing_column FROM ' . $this->table . ' WHERE id = ?'),
				fn () => $this->db->executeQuery('SELECT missing_column FROM ' . $this->table),
				fn () => $this->db->executeStatement('UPDATE ' . $this->table . ' SET missing_column = 1'),
			] as $operation) {
				try {
					$operation();
					$this->fail('Invalid SQL must raise a Doctrine exception.');
				} catch (InvalidFieldNameException) {
					// The failed operation must restore the caller's reporting mode.
				}

				$this->assertThat((new mysqli_driver())->report_mode, $this->identicalTo($mode));
			}

			try {
				$statement->executeStatement();
				$this->fail('Prepared execution must raise a Doctrine constraint exception.');
			} catch (UniqueConstraintViolationException) {
				// Prepared execution must restore the caller's reporting mode too.
			}

			$this->assertThat((new mysqli_driver())->report_mode, $this->identicalTo($mode));
		} finally {
			$driver->report_mode = $original;
		}
	}

	public function test_transaction_and_advisory_callbacks_keep_wordpress_reporting(): void {
		$driver              = new mysqli_driver();
		$original            = $driver->report_mode;
		$driver->report_mode = MYSQLI_REPORT_OFF;

		try {
			$this->db->transactional(function (): void {
				$this->assertSame(MYSQLI_REPORT_OFF, (new mysqli_driver())->report_mode);
				$this->assertSame('Original', $this->db->fetchOne('SELECT name FROM ' . $this->table));
				$this->assertSame(MYSQLI_REPORT_OFF, (new mysqli_driver())->report_mode);
			});

			$this->assertSame(MYSQLI_REPORT_OFF, (new mysqli_driver())->report_mode);
			$session = $this->container->get(AdvisorySession::class);
			$session->withAdvisoryLock($this->suffix, function (): void {
				$this->assertSame(MYSQLI_REPORT_OFF, (new mysqli_driver())->report_mode);
				$this->assertSame('Original', $this->db->fetchOne('SELECT name FROM ' . $this->table));
				$this->assertSame(MYSQLI_REPORT_OFF, (new mysqli_driver())->report_mode);
			});

			$this->assertSame(MYSQLI_REPORT_OFF, (new mysqli_driver())->report_mode);
			$failure = new RuntimeException('Application failure');
			$caught  = null;

			try {
				$session->withAdvisoryLock($this->suffix, static fn () => throw $failure);
			} catch (RuntimeException $caught) {
				// Check identity below so a swallowed failure cannot satisfy the test.
			}

			$this->assertSame($failure, $caught);
			$this->assertSame(MYSQLI_REPORT_OFF, (new mysqli_driver())->report_mode);
			$suppressed = $this->source->suppress_errors(true);

			try {
				$this->assertFalse($this->source->query('SELECT missing_column FROM ' . $this->table));
			} finally {
				$this->source->suppress_errors($suppressed);
			}
		} finally {
			$driver->report_mode = $original;
		}
	}
}
