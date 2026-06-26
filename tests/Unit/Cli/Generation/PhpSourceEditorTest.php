<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use PhpParser\Lexer;
use PhpParser\ParserFactory;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;
use StellarWP\Foundation\Tests\TestCase;

final class PhpSourceEditorTest extends TestCase
{
	public function test_it_reports_parse_failures_for_invalid_php(): void {
		$editor = $this->editor();

		$this->assertFalse($editor->canParse('<?php final class Broken {'));
		$this->assertNull($editor->addImport('<?php final class Broken {', 'Acme\\Generated'));
		$this->assertFalse($editor->canInsertIntoMergeArrayVar(
			'<?php final class Broken {',
			'StellarWP\\Foundation\\Database\\DatabaseProvider',
			'MIGRATIONS'
		));
		$this->assertNull($editor->insertIntoMergeArrayVar(
			'<?php final class Broken {',
			'StellarWP\\Foundation\\Database\\DatabaseProvider',
			'MIGRATIONS',
			'$c->get(Generated::class),'
		));
	}

	public function test_it_returns_existing_source_when_an_import_already_exists(): void {
		$contents = $this->fixture('existing-import');

		$this->assertSame($contents, $this->editor()->addImport($contents, 'Acme\\Generated'));
	}

	public function test_it_returns_null_when_a_line_comment_cannot_be_found(): void {
		$this->assertNull($this->editor()->insertBeforeLineComment(
			'<?php declare(strict_types=1); namespace Acme; final class Provider {}',
			'// foundation:missing',
			'$this->container->singleton(Generated::class);'
		));
	}

	public function test_it_adds_imports_to_files_without_namespaces_or_opening_tags(): void {
		$editor = $this->editor();

		$this->assertStringStartsWith(
			"<?php declare(strict_types=1);\n\nuse Acme\\Generated;",
			(string) $editor->addImport('<?php declare(strict_types=1); final class Provider {}', 'Acme\\Generated')
		);
		$this->assertStringContainsString(
			'use Acme\\Generated;final class Provider {}',
			(string) $editor->addImport('final class Provider {}', 'Acme\\Generated')
		);
	}

	public function test_it_adds_imports_after_a_last_use_without_a_trailing_newline(): void {
		$contents = <<<'PHP'
<?php declare(strict_types=1);

namespace Acme;

use Acme\Existing;
final class Provider {}
PHP;

		$this->assertStringContainsString(
			"use Acme\\Existing;\nuse Acme\\Generated;\nfinal class Provider",
			(string) $this->editor()->addImport($contents, 'Acme\\Generated')
		);
	}

	public function test_it_ignores_function_imports_when_resolving_class_imports(): void {
		$this->assertFalse($this->editor()->hasImport($this->fixture('function-import'), 'Acme\\Generated'));
	}

	public function test_it_rejects_merge_array_var_calls_that_are_not_foundation_database_migration_lists(): void {
		$editor = $this->editor();
		$class  = 'StellarWP\\Foundation\\Database\\DatabaseProvider';

		$this->assertFalse($editor->canInsertIntoMergeArrayVar($this->fixture('not-container-merge-array-var'), $class, 'MIGRATIONS'));
		$this->assertFalse($editor->canInsertIntoMergeArrayVar($this->fixture('wrong-first-argument-merge-array-var'), $class, 'MIGRATIONS'));
		$this->assertFalse($editor->canInsertIntoMergeArrayVar($this->fixture('wrong-constant-merge-array-var'), $class, 'MIGRATIONS'));
	}

	public function test_it_matches_fully_qualified_strauss_prefixed_database_provider_references(): void {
		$updated = $this->editor()->insertIntoMergeArrayVar(
			$this->fixture('strauss-prefixed-database-provider'),
			'StellarWP\\Foundation\\Database\\DatabaseProvider',
			'MIGRATIONS',
			'$this->container->get(Generated::class),'
		);

		$this->assertStringContainsString('$this->container->get(Generated::class),', (string) $updated);
	}

	public function test_it_rejects_merge_array_var_callbacks_that_do_not_expose_a_registration_list(): void {
		$this->assertFalse($this->editor()->canInsertIntoMergeArrayVar(
			$this->fixture('arrow-callback-without-registration-list'),
			'StellarWP\\Foundation\\Database\\DatabaseProvider',
			'MIGRATIONS'
		));
	}

	public function test_it_rejects_merge_array_var_closures_without_container_parameters_or_array_returns(): void {
		$editor = $this->editor();
		$class  = 'StellarWP\\Foundation\\Database\\DatabaseProvider';

		$this->assertFalse($editor->canInsertIntoMergeArrayVar($this->fixture('closure-without-container-parameter'), $class, 'MIGRATIONS'));
		$this->assertFalse($editor->canInsertIntoMergeArrayVar($this->fixture('closure-without-array-return'), $class, 'MIGRATIONS'));
	}

	public function test_it_uses_space_indentation_when_inserting_into_space_indented_arrays(): void {
		$updated = $this->editor()->insertIntoMergeArrayVar(
			$this->fixture('space-indented-registration-list'),
			'StellarWP\\Foundation\\Database\\DatabaseProvider',
			'MIGRATIONS',
			'$this->container->get(Generated::class),'
		);

		$this->assertStringContainsString(
			"            \$this->container->get(Existing::class),\n            \$this->container->get(Generated::class),",
			(string) $updated
		);
	}

	private function editor(): PhpSourceEditor {
		return new PhpSourceEditor(
			parserFactory: new ParserFactory(),
			lexer: new Lexer()
		);
	}

	private function fixture(string $name): string {
		return (string) file_get_contents($this->data_dir('cli/generation/php-source-editor/' . $name . '.stub'));
	}
}
