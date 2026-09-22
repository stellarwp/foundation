<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * A second table created between the entries migrations.
 */
final readonly class CreateEntryTags implements Migration
{
	public const string ID = '20260922000150';

	public function __construct(
		private TagsTable $table,
	) {
	}

	public function id(): string {
		return self::ID;
	}

	public function up(Blueprint $schema): void {
		$tags = $schema->create($this->table);
		$tags->bigIncrements('id');
		$tags->string('label', 50);
	}

	public function down(Blueprint $schema): void {
		$schema->drop($this->table);
	}
}
