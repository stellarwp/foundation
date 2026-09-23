<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\NativeConnectionFactory;
use Throwable;

final class NativeBehaviorTest extends DatabaseTestCase
{
	public function test_native_dbal_commits_after_a_caught_statement_failure(): void {
		$db = (new NativeConnectionFactory())->create($this->native($this->source), constant('DB_NAME'));
		$db->transactional(function () use ($db): void {
			$db->update($this->table, ['name' => 'Committed despite failure'], ['id' => 1]);

			try {
				$db->insert($this->table, ['id' => 1, 'name' => 'Duplicate']);
			} catch (UniqueConstraintViolationException) {
			}
		});
		self::assertSame('Committed despite failure', $this->observer->fetchOne('SELECT name FROM ' . $this->table));
	}

	public function test_native_dbal_rejects_unacknowledged_commit(): void {
		$db = (new NativeConnectionFactory())->create($this->native($this->source), constant('DB_NAME'));

		try {
			@$db->transactional(function () use ($db): string {
				$db->update($this->table, ['name' => 'Lost'], ['id' => 1]);
				$this->killConnection();

				return 'success';
			});
			self::fail('Expected DBAL to reject the unacknowledged commit.');
		} catch (ConnectionLost) {
			$this->assertOriginal();
		}
	}

	public function test_native_cleanup_can_replace_the_business_exception(): void {
		$db       = (new NativeConnectionFactory())->create($this->native($this->source), constant('DB_NAME'));
		$business = new RuntimeException('Business failure');

		try {
			$db->transactional(function () use ($business): void {
				$this->native($this->source)->close();

				throw $business;
			});
		} catch (Throwable $caught) {
			self::assertNotSame($business, $caught);
			self::assertStringContainsString('closed', $caught->getMessage());
		}
	}
}
