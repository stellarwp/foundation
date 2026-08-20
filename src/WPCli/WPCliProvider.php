<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

use StellarWP\Foundation\Container\Contracts\Provider;
use UnexpectedValueException;

/**
 * Registers Foundation WP-CLI commands contributed by application providers.
 *
 * Applications should register this provider once, then use
 * {@see self::COMMANDS} with the container's additive array binding to
 * contribute command classes from feature-specific providers.
 */
final class WPCliProvider extends Provider
{
	public const string COMMANDS       = 'foundation.wpcli.commands';
	public const string COMMAND_PREFIX = 'foundation.wpcli.command_prefix';

	public function register(): void {
		$this->container->mergeArrayVar(self::COMMANDS, []);
		$this->container->bind(self::COMMAND_PREFIX, $this->config->get('wpcli.command_prefix', 'nx'));

		add_action('cli_init', function (): void {
			$this->registerCommands();
		}, 0, 0);
	}

	/**
	 * @throws UnexpectedValueException When the configured command list contains an invalid value.
	 */
	private function registerCommands(): void {
		$commands = $this->container->get(self::COMMANDS);

		if (! is_iterable($commands)) {
			throw new UnexpectedValueException(sprintf(
				'WP-CLI commands must be iterable; received %s.',
				get_debug_type($commands)
			));
		}

		$commands = is_array($commands) ? array_values($commands) : iterator_to_array($commands, false);

		foreach ($commands as $index => $command) {
			if (! $command instanceof Command) {
				throw new UnexpectedValueException(sprintf(
					'WP-CLI command at index %d must extend %s; received %s.',
					$index,
					Command::class,
					get_debug_type($command)
				));
			}
		}

		foreach ($commands as $command) {
			$command->register();
		}
	}
}
