<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Schema;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\Editor;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class EditorTest extends TestCase
{
	public function test_it_applies_one_explicit_table_alteration(): void {
		$database = new FakeDatabase();
		$editor   = new Editor($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$table    = new TestTable('reports');
		$change   = Blueprint::for($table);

		$change->dropIndex('legacy_status');
		$change->dropColumn('legacy_payload');
		$change->dateTime('published_at')->nullable();
		$change->string('status', 40)->default('draft')->change();
		$change->index('status_published_at', 'status', 'published_at');

		$database->rowResults = [
			['table' => 'wp_reports'],
			['Key_name' => 'legacy_status'],
			['Field'    => 'legacy_payload'],
			null,
			['Field'    => 'status'],
			['Type'     => 'varchar(20)', 'Null' => 'NO', 'Default' => 'draft', 'Extra' => '', 'Comment' => ''],
			null,
			['Type'     => 'datetime', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Comment' => ''],
			['Type'     => 'varchar(40)', 'Null' => 'NO', 'Default' => 'draft', 'Extra' => '', 'Comment' => ''],
			null,
			null,
		];
		$database->rowsResults[] = [
			self::indexRow('status_published_at', 1, 1, 'status'),
			self::indexRow('status_published_at', 1, 2, 'published_at'),
		];

		$editor->alter($change);

		$this->assertSame([
			'ALTER TABLE `wp_reports` DROP INDEX `legacy_status`, DROP COLUMN `legacy_payload`, ADD COLUMN `published_at` datetime NULL, MODIFY COLUMN `status` varchar(40) NOT NULL DEFAULT \'draft\', ADD KEY `status_published_at` (`status`, `published_at`)',
		], $database->executed);
	}

	public function test_it_treats_completed_additions_and_removals_as_a_retry(): void {
		$database = new FakeDatabase();
		$editor   = new Editor($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$change   = Blueprint::for(new TestTable('reports'));

		$change->dropIndex('legacy_status');
		$change->dateTime('published_at')->nullable();
		$change->index('published_at', 'published_at');

		$database->rowResults = [
			['table' => 'wp_reports'],
			null,
			['Field'    => 'published_at'],
			['Type'     => 'datetime', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Comment' => ''],
			['Key_name' => 'published_at'],
			['Type'     => 'datetime', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Comment' => ''],
			null,
		];
		$database->rowsResults = [[
			self::indexRow('published_at', 1, 1, 'published_at'),
		], [
			self::indexRow('published_at', 1, 1, 'published_at'),
		]];

		$editor->alter($change);

		$this->assertSame([], $database->executed);
	}

	public function test_it_rejects_a_change_to_a_missing_column(): void {
		$database = new FakeDatabase();
		$editor   = new Editor($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$change   = Blueprint::for(new TestTable('reports'));

		$change->string('status', 40)->change();

		$database->rowResults = [
			['table' => 'wp_reports'],
			null,
		];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('Cannot change missing column status on wp_reports.');

		$editor->alter($change);
	}

	public function test_it_rejects_an_incompatible_existing_column_before_executing_removals(): void {
		$database = new FakeDatabase();
		$editor   = new Editor($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$change   = Blueprint::for(new TestTable('reports'));

		$change->dropColumn('legacy_payload');
		$change->string('status', 40);

		$database->rowResults = [
			['table' => 'wp_reports'],
			['Field' => 'legacy_payload'],
			['Field' => 'status'],
			['Type'  => 'int(10)', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
		];

		try {
			$editor->alter($change);
			$this->fail('Expected the incompatible existing column to be rejected.');
		} catch (DatabaseException $exception) {
			$this->assertStringContainsString('column status expected type varchar(40), found int(10)', $exception->getMessage());
		}

		$this->assertSame([], $database->executed);
	}

	public function test_it_rejects_an_incompatible_existing_index_before_executing_removals(): void {
		$database = new FakeDatabase();
		$editor   = new Editor($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$change   = Blueprint::for(new TestTable('reports'));

		$change->dropColumn('legacy_payload');
		$change->index('status_lookup', 'status');

		$database->rowResults = [
			['table' => 'wp_reports'],
			['Field'    => 'legacy_payload'],
			['Key_name' => 'status_lookup'],
		];
		$database->rowsResults[] = [
			self::indexRow('status_lookup', 1, 1, 'legacy_status'),
		];

		try {
			$editor->alter($change);
			$this->fail('Expected the incompatible existing index to be rejected.');
		} catch (DatabaseException $exception) {
			$this->assertStringContainsString('index status_lookup expected KEY (status), found KEY (legacy_status)', $exception->getMessage());
		}

		$this->assertSame([], $database->executed);
	}

	public function test_it_skips_a_column_change_that_already_matches_the_requested_state(): void {
		$database = new FakeDatabase();
		$editor   = new Editor($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$change   = Blueprint::for(new TestTable('reports'));

		$change->string('status', 40)->default('draft')->change();

		$properties           = ['Type' => 'varchar(40)', 'Null' => 'NO', 'Default' => 'draft', 'Extra' => '', 'Comment' => ''];
		$database->rowResults = [
			['table' => 'wp_reports'],
			['Field' => 'status'],
			$properties,
			$properties,
		];

		$editor->alter($change);

		$this->assertSame([], $database->executed);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function indexRow(
		string $name,
		int $nonUnique,
		int $sequence,
		string $column
	): array {
		return [
			'Key_name'     => $name,
			'Non_unique'   => $nonUnique,
			'Seq_in_index' => $sequence,
			'Column_name'  => $column,
			'Sub_part'     => null,
			'Index_type'   => 'BTREE',
			'Collation'    => 'A',
		];
	}
}
