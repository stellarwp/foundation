<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use StellarWP\Foundation\Database\Table\Collection;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class CollectionTest extends TestCase
{
	public function test_it_creates_only_missing_tables(): void {
		$existing = new TestTable('existing_table', 'existing');
		$missing  = new TestTable('missing_table', 'missing');
		$schema   = new RecordingSchema();

		$schema->tables['existing'] = true;
		$collection                 = new Collection($schema, [$existing, $missing]);

		$collection->create();

		$this->assertSame(['createOrUpdate:missing'], $schema->statements);
		$this->assertTrue($schema->hasTable($existing));
		$this->assertTrue($schema->hasTable($missing));
	}

	public function test_it_drops_all_tables(): void {
		$first  = new TestTable('first_table', 'first');
		$second = new TestTable('second_table', 'second');
		$schema = new RecordingSchema();

		$collection = new Collection($schema, [$first]);
		$collection->add($second);
		$collection->drop();

		$this->assertSame(['drop:first', 'drop:second'], $schema->statements);
		$this->assertSame([$first, $second], $collection->all());
		$this->assertSame([$first, $second], iterator_to_array($collection));
	}

	public function test_it_checks_whether_all_tables_exist(): void {
		$schema = new RecordingSchema();
		$first  = new TestTable('first_table', 'first');
		$second = new TestTable('second_table', 'second');

		$schema->tables = [
			'first'  => true,
			'second' => true,
		];

		$this->assertTrue((new Collection($schema, [$first, $second]))->exists());

		unset($schema->tables['second']);

		$this->assertFalse((new Collection($schema, [$first, $second]))->exists());
	}
}
