<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\WPCli;

use StellarWP\Foundation\WPCli\Command;

final class TestCommand extends Command
{
	/**
	 * @var list<mixed>
	 */
	public array $args = [];

	/**
	 * @var array<string,mixed>
	 */
	public array $assocArgs = [];

	/**
	 * @var resource|null
	 */
	private mixed $input = null;

	/**
	 * @var resource|null
	 */
	private mixed $output = null;

	/**
	 * @param list<mixed>         $args
	 * @param array<string,mixed> $assocArgs
	 */
	public function runCommand(array $args = [], array $assocArgs = []): int {
		$this->args      = $args;
		$this->assocArgs = $assocArgs;

		return self::SUCCESS;
	}

	public function name(): string {
		return $this->command();
	}

	public function shortDescription(): string {
		return $this->description();
	}

	/**
	 * @return array{}|list<array{type: string, name: string, description: string, default?: mixed, optional?: bool, repeating?: bool, options?: list<mixed>}>
	 */
	public function synopsis(): array {
		return $this->arguments();
	}

	public function prompt(string $question): string {
		return $this->ask($question);
	}

	public function defaultInput(): mixed {
		return $this->input();
	}

	public function defaultOutput(): mixed {
		return $this->output();
	}

	/**
	 * @return array{answer: string, output: string}
	 */
	public function promptWithInput(string $question, string $input): array {
		$inputStream = fopen('php://memory', 'r+');

		if ($inputStream === false) {
			throw new \RuntimeException('Could not open memory input stream.');
		}

		$this->input = $inputStream;
		fwrite($this->input, $input);
		rewind($this->input);

		$outputStream = fopen('php://memory', 'w+');

		if ($outputStream === false) {
			throw new \RuntimeException('Could not open memory output stream.');
		}

		$this->output = $outputStream;

		$answer = $this->ask($question);

		rewind($this->output);
		$output = stream_get_contents($this->output);

		$this->input  = null;
		$this->output = null;

		return [
			'answer' => $answer,
			'output' => $output,
		];
	}

	protected function subcommand(): string {
		return 'example';
	}

	protected function description(): string {
		return 'Example command.';
	}

	protected function arguments(): array {
		return [
			[
				'type'        => self::POSITIONAL,
				'name'        => 'value',
				'description' => 'Value to process.',
			],
		];
	}

	protected function input(): mixed {
		return $this->input ?? parent::input();
	}

	protected function output(): mixed {
		return $this->output ?? parent::output();
	}
}
