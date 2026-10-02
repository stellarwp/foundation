<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Let MySQL acquire a real lock, then simulate losing its acknowledgement.
 */
final class LostAdvisoryLockReply extends mysqli
{
	/**
	 * Execute real SQL before injecting the acquisition failure.
	 */
	public function prepare(string $query): mysqli_stmt|false {
		if (str_contains($query, 'GET_LOCK(')) {
			parent::query($query);

			throw new RuntimeException('Lost advisory lock acquisition reply');
		}

		return parent::prepare($query);
	}
}
