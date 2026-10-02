<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Types;
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

	public function test_named_bindings_preserve_explicit_values_and_infer_remaining_types(): void {
		$date     = new DateTimeImmutable('2026-10-02 12:34:56.123456');
		$json     = new JsonType();
		$bindings = [
			'metadata' => [
				'source' => 'portal',
			],
			'created'  => $date,
			'ids'      => [
				1,
				2,
			],
			'explicit' => 500,
			'amount'   => 500,
			'code'     => '00500',
		];
		$types = [
			'metadata' => $json,
			'created'  => Types::DATETIME_IMMUTABLE,
			'ids'      => ArrayParameterType::INTEGER,
			'explicit' => ParameterType::STRING,
		];
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->once())->method('executeStatement')->with('UPDATE', $bindings, [
			...$types,
			'amount' => ParameterType::INTEGER,
			'code'   => ParameterType::STRING,
		])->willReturn(2);

		$this->assertSame(2, (new Executor($connection))->executeStatement('UPDATE', $bindings, $types));
	}

	public function test_positional_type_overrides_keep_their_placeholder_positions(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->once())->method('executeStatement')->with('UPDATE', [
			1,
			500,
			null,
		], [
			1 => ParameterType::STRING,
			0 => ParameterType::INTEGER,
			2 => ParameterType::NULL,
		])->willReturn(1);

		$this->assertSame(1, (new Executor($connection))->executeStatement('UPDATE', [
			true,
			500,
			null,
		], [
			1 => ParameterType::STRING,
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
