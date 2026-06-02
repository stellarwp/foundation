<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

use StellarWP\Foundation\Container\Contracts\Container;
use WP_CLI;
use WP_CLI_Command;

/**
 * Base class for WP-CLI commands that use the Foundation container.
 *
 * Extend this class in an application package when a command needs container
 * access and should register itself with WP-CLI using a consistent synopsis.
 */
abstract class Command extends WP_CLI_Command
{
	protected const string POSITIONAL  = 'positional';
	protected const string ASSOCIATIVE = 'assoc';
	protected const string FLAG        = 'flag';
	protected const int SUCCESS        = 0;
	protected const int ERROR          = 1;

	public function __construct(
		protected Container $container,
		private readonly string $commandPrefix
	) {
		parent::__construct();
	}

	/**
	 * @param list<mixed>         $args
	 * @param array<string,mixed> $assocArgs
	 *
	 * @return int 0 is success; any other value is an error.
	 */
	abstract public function runCommand(array $args = [], array $assocArgs = []): int;

	/**
	 * The command name under the configured prefix, e.g. "sync".
	 */
	abstract protected function subcommand(): string;

	/**
	 * The command description as it appears in "wp help".
	 */
	abstract protected function description(): string;

	/**
	 * The array of command arguments/options the command accepts.
	 *
	 * @return array{}|list<array{type: string, name: string, description: string, default?: mixed, optional?: bool, repeating?: bool, options?: list<mixed>}>
	 */
	abstract protected function arguments(): array;

	/**
	 * Register the command with WP-CLI.
	 */
	public function register(): void {
		WP_CLI::add_command($this->command(), [$this, 'runCommand'], [
			'shortdesc' => $this->description(),
			'synopsis'  => $this->arguments(),
		]);
	}

	protected function command(): string {
		return trim($this->commandPrefix . ' ' . $this->subcommand());
	}

	/**
	 * Ask a question and retrieve a normalized answer from STDIN.
	 */
	protected function ask(string $question): string {
		fwrite($this->output(), $question . ' ');

		return strtolower(trim((string) fgets($this->input())));
	}

	/**
	 * @return resource
	 */
	protected function input(): mixed {
		return STDIN;
	}

	/**
	 * @return resource
	 */
	protected function output(): mixed {
		return STDOUT;
	}
}
