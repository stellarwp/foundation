<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * Recreate the tags table with a different definition after it was dropped.
 */
final readonly class RecreateEntryTags implements Migration
{
	public const string ID = '20260922000170';

	public function __construct(
		private TagsTable $table,
	) {
	}

	public function up(Blueprint $schema): void {
		$tags = $schema->create($this->table);
		$tags->bigIncrements('id');
		$tags->string('label', 80);
		$tags->string('slug', 80);
	}

	public function down(Blueprint $schema): void {
		$schema->drop($this->table);
	}
}
