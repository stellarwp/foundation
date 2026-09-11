<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

use StellarWP\Foundation\WPCli\Contracts\RegistrableCommand;
use StellarWP\Foundation\WPCli\Exceptions\CommandAlreadyRegistered;
use WP_CLI;
use WP_CLI_Command;

/**
 * Base class for WP-CLI commands registered through Foundation.
 *
 * Extend this class in an application package when a command should register
 * itself with WP-CLI using a consistent synopsis and exit-status behavior.
 */
abstract class Command extends WP_CLI_Command implements RegistrableCommand
{
	protected const string POSITIONAL  = 'positional';
	protected const string ASSOCIATIVE = 'assoc';
	protected const string FLAG        = 'flag';
	protected const int SUCCESS        = 0;
	protected const int ERROR          = 1;

	private ?CommandContext $context = null;

	/**
	 * Execute the command using normalized positional and associative arguments.
	 *
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
	 *
	 * @throws CommandAlreadyRegistered When this command instance has already been registered.
	 */
	public function register(CommandContext $context): void {
		if ($this->context !== null) {
			throw new CommandAlreadyRegistered(sprintf(
				'%s has already been registered as "%s".',
				static::class,
				$this->command($this->context)
			));
		}

		$name           = $this->command($context);
		$deferredBefore = WP_CLI::get_deferred_additions();

		$registered = WP_CLI::add_command($name, function (array $args, array $assocArgs): void {
			$status = $this->runCommand(array_values($args), $assocArgs);

			if ($status !== self::SUCCESS) {
				WP_CLI::halt($status);
			}
		}, [
			'shortdesc' => $this->description(),
			'synopsis'  => $this->arguments(),
		]);

		$deferredAfter = WP_CLI::get_deferred_additions();
		$wasDeferred   = array_key_exists($name, $deferredAfter)
			&& (! array_key_exists($name, $deferredBefore) || $deferredAfter[$name] !== $deferredBefore[$name]);

		if ($registered || $wasDeferred) {
			$this->context = $context;
		}
	}

	/**
	 * Build the complete WP-CLI command name for the supplied context.
	 */
	protected function command(CommandContext $context): string {
		return $context->name($this->subcommand());
	}

	/**
	 * Return the configured command name after this command has been registered.
	 */
	final protected function registeredCommandName(): ?string {
		return $this->context === null ? null : $this->command($this->context);
	}

	/**
	 * Ask a question and retrieve a normalized answer from STDIN.
	 */
	protected function ask(string $question): string {
		fwrite($this->output(), $question . ' ');

		return strtolower(trim((string) fgets($this->input())));
	}

	/**
	 * Return the stream used to read interactive command input.
	 *
	 * @return resource
	 */
	protected function input(): mixed {
		return STDIN;
	}

	/**
	 * Return the stream used to write interactive command output.
	 *
	 * @return resource
	 */
	protected function output(): mixed {
		return STDOUT;
	}
}
