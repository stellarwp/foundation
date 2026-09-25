<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Query\IdentifierQuoter;
use StellarWP\Foundation\Database\Query\JoinClause;
use StellarWP\Foundation\Database\Query\WhereGroup;

final class WhereGroupTest extends TestCase
{
	public function test_nested_groups_keep_boolean_precedence_and_binding_order(): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$group->where('e.source', 'sdn')->where(
			static fn (WhereGroup $alternatives) => $alternatives->where('e.name', 'first')->orWhere('e.name', 'second'),
		)->whereIn('e.id', [
			3,
			7,
		]);
		$this->assertSame('`e`.`source` = ? AND (`e`.`name` = ? OR `e`.`name` = ?) AND `e`.`id` IN (?, ?)', $group->condition()->sql);
		$this->assertSame([
			'sdn',
			'first',
			'second',
			3,
			7,
		], $group->condition()->bindings);
	}

	public function test_substring_needles_escape_wildcards_without_reinterpreting_inserted_escapes(): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$group->whereContainsAny('name', [
			'!%_',
			"x' OR 1 = 1 --",
			'',
		]);
		$this->assertSame("(`name` LIKE ? ESCAPE '!' OR `name` LIKE ? ESCAPE '!' OR `name` LIKE ? ESCAPE '!')", $group->condition()->sql);
		$this->assertSame([
			'%!!!%!_%',
			"%x' OR 1 = 1 --%",
			'%%',
		], $group->condition()->bindings);
	}

	public function test_empty_sets_and_empty_needle_sets_have_explicit_semantics(): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$group->whereIn('id', [])->whereNotIn('id', [])->whereContainsAny('name', []);
		$this->assertSame('0 = 1 AND 1 = 1 AND 0 = 1', $group->condition()->sql);
		$this->assertSame([], $group->condition()->bindings);
	}

	public function test_null_equalities_do_not_bind_null_as_an_ordinary_comparison(): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$group->where('a', null)->where('b', '!=', null)->where('c', '<>', null);
		$this->assertSame('`a` IS NULL AND `b` IS NOT NULL AND `c` IS NOT NULL', $group->condition()->sql);
		$this->assertSame([], $group->condition()->bindings);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidNullOperators(): iterable {
		yield 'ordering' => [
			'>',
		];

		yield 'injection' => [
			'= 1 OR id =',
		];

		yield 'unsupported' => [
			'LIKE',
		];
	}

	/**
	 * @dataProvider invalidNullOperators
	 */
	#[DataProvider('invalidNullOperators')]
	public function test_null_never_bypasses_operator_validation(string $operator): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$this->expectException(InvalidArgumentException::class);
		$group->where('id', $operator, null);
	}

	public function test_failed_group_does_not_mutate_existing_conditions(): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$group->where('id', 1);

		try {
			$group->where(static function (WhereGroup $nested): void {
				$nested->where('status', 'active')->where('deleted', '>', null);
			});
			$this->fail('Invalid null ordering must throw.');
		} catch (InvalidArgumentException) {
			$this->assertSame('`id` = ?', $group->condition()->sql);
			$this->assertSame([
				1,
			], $group->condition()->bindings);
		}
	}

	public function test_numeric_criteria_keys_are_rejected_without_changing_existing_conditions(): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$group->where('id', 1);

		try {
			$group->where([
				'status' => 'active',
				0        => 'x',
			]);
			$this->fail('Criteria with numeric keys must throw.');
		} catch (InvalidArgumentException $failure) {
			$this->assertSame('Criteria must use column names as string keys.', $failure->getMessage());
			$this->assertSame('`id` = ?', $group->condition()->sql);
			$this->assertSame([
				1,
			], $group->condition()->bindings);
		}
	}

	public function test_an_empty_nested_group_is_rejected(): void {
		$group = new WhereGroup(new IdentifierQuoter());
		$this->expectException(InvalidArgumentException::class);
		$group->where(static function (WhereGroup $nested): void {
		});
	}

	public function test_nested_join_groups_keep_column_comparisons_and_binding_order(): void {
		$join = new JoinClause(new IdentifierQuoter());
		$join->where(static fn (JoinClause $group) => $group
			->on('e.owner_id', '=', 'u.id')
			->orWhere(static fn (JoinClause $nested) => $nested
				->on('e.fallback_id', '=', 'u.id')
				->where('u.active', true)))
			->where('e.status', 'open');

		$this->assertSame('(`e`.`owner_id` = `u`.`id` OR (`e`.`fallback_id` = `u`.`id` AND `u`.`active` = ?)) AND `e`.`status` = ?', $join->condition()->sql);
		$this->assertSame([
			true,
			'open',
		], $join->condition()->bindings);
	}

	public function test_join_distinguishes_column_relationships_from_bound_values(): void {
		$join = new JoinClause(new IdentifierQuoter());
		$join->on('e.owner_id', '=', 'u.ID')->where('u.user_status', 0)->orOn('e.fallback_id', '=', 'u.ID');
		$this->assertSame('`e`.`owner_id` = `u`.`ID` AND `u`.`user_status` = ? OR `e`.`fallback_id` = `u`.`ID`', $join->condition()->sql);
		$this->assertSame([
			0,
		], $join->condition()->bindings);
	}

	public function test_native_doctrine_builder_expresses_the_same_grouped_join_with_explicit_identifiers(): void {
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$native      = new QueryBuilder($connection);
		$expressions = new ExpressionBuilder($connection);
		$native->select('`e`.`id`')
			->from('`wp_entries`', '`e`')
			->innerJoin('`e`', '`wp_users`', '`u`', $expressions->eq('`e`.`owner_id`', '`u`.`ID`'))
			->where($expressions->and(
				$expressions->eq('`e`.`source`', '?'),
				$expressions->or($expressions->eq('`u`.`user_status`', '?'), $expressions->isNull('`u`.`user_status`')),
			))
			->setParameters([
				'sdn',
				0,
			], [
				ParameterType::STRING,
				ParameterType::INTEGER,
			]);
		$conditions = new WhereGroup(new IdentifierQuoter());
		$conditions->where('e.source', 'sdn')->where(
			static fn (WhereGroup $nested) => $nested->where('u.user_status', 0)->orWhere('u.user_status', null),
		);
		$join = new JoinClause(new IdentifierQuoter());
		$join->on('e.owner_id', '=', 'u.ID');
		$this->assertSame('SELECT `e`.`id` FROM `wp_entries` `e` INNER JOIN `wp_users` `u` ON `e`.`owner_id` = `u`.`ID` WHERE (`e`.`source` = ?) AND ((`u`.`user_status` = ?) OR (`u`.`user_status` IS NULL))', $native->getSQL());
		$this->assertSame('`e`.`source` = ? AND (`u`.`user_status` = ? OR `u`.`user_status` IS NULL)', $conditions->condition()->sql);
		$this->assertSame($native->getParameters(), $conditions->condition()->bindings);
		$this->assertSame('`e`.`owner_id` = `u`.`ID`', $join->condition()->sql);
		$rows = [
			[
				'id' => 7,
			],
		];
		$result = $this->createMock(Result::class);
		$result->expects($this->once())->method('fetchAllAssociative')->willReturn($rows);
		$connection->expects($this->once())->method('executeQuery')->with($native->getSQL(), $conditions->condition()->bindings, [
			ParameterType::STRING,
			ParameterType::INTEGER,
		])->willReturn($result);
		$this->assertSame($rows, $native->fetchAllAssociative());
	}
}
