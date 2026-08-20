<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\DbDelta;
use StellarWP\Foundation\Tests\TestCase;

final class DbDeltaTest extends TestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_fails_when_wordpress_db_delta_is_unavailable(): void {
		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('WordPress dbDelta() is not available.');

		(new DbDelta())->execute('CREATE TABLE example (id bigint)');
	}
}
