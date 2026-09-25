<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use StellarWP\Foundation\Database\Query\Executor;
use StellarWP\Foundation\Database\Query\ValueObjects\Fragment;

final class ExecutorTest extends TestCase
{
	public function test_execution_normalizes_values_and_infers_explicit_types(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->once())->method('executeStatement')->with('INSERT', [
			null,
			0,
			1,
			3,
			'4.5',
			'6.70',
			'2026-09-25 12:34:56.123456',
		], [
			ParameterType::NULL,
			ParameterType::INTEGER,
			ParameterType::INTEGER,
			ParameterType::INTEGER,
			ParameterType::STRING,
			ParameterType::STRING,
			ParameterType::STRING,
		])->willReturn('9223372036854775808');
		$executor = new Executor($connection);
		$this->assertSame('9223372036854775808', $executor->statement(new Fragment('INSERT', [
			null,
			false,
			true,
			3,
			4.5,
			'6.70',
			new DateTimeImmutable('2026-09-25 12:34:56.123456+08:00'),
		])));
	}

	public function test_unsupported_values_fail_before_execution(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$this->expectException(InvalidArgumentException::class);
		(new Executor($connection))->statement(new Fragment('INSERT', [
			new stdClass(),
		]));
	}

	public function test_reads_preserve_dbal_results(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->once())->method('fetchAllAssociative')->with('SELECT', [
			1,
		], [
			ParameterType::INTEGER,
		])->willReturn([
			[
				'id' => '12',
			],
		]);
		$connection->expects($this->once())->method('fetchAssociative')->willReturn(false);
		$connection->expects($this->once())->method('fetchOne')->willReturn(null);
		$executor = new Executor($connection);
		$this->assertSame([
			[
				'id' => '12',
			],
		], $executor->rows(new Fragment('SELECT', [
			true,
		])));
		$this->assertFalse($executor->first(new Fragment('SELECT')));
		$this->assertNull($executor->value(new Fragment('SELECT')));
	}
}
