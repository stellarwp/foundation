<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Table\ValueObjects\ColumnComment;

final class ColumnCommentTest extends TestCase
{
	public function test_it_renders_a_sql_literal_without_depending_on_backslash_escaping(): void {
		$comment = new ColumnComment("Customer's description; internal metadata");

		$this->assertSame("'Customer''s description; internal metadata'", $comment->sql());
	}

	public function test_it_rejects_backslashes(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Database column comments cannot contain backslashes.');

		new ColumnComment('Filesystem path: C:\\cache');
	}

	public function test_it_rejects_control_characters(): void {
		foreach (["line\nbreak", "carriage\rreturn", "tab\tcharacter", "null\0byte", "delete\x7Fbyte"] as $comment) {
			try {
				new ColumnComment($comment);
				$this->fail('Expected the database column comment to be rejected.');
			} catch (InvalidArgumentException $exception) {
				$this->assertSame('Database column comments cannot contain control characters.', $exception->getMessage());
			}
		}
	}
}
