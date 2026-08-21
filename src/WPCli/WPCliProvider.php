<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

use InvalidArgumentException;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Traits\ResolvesFoundationPrefix;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;
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
	use ResolvesFoundationPrefix;

	public const string COMMANDS = 'foundation.wpcli.commands';

	/**
	 * @throws InvalidArgumentException When the configured Foundation prefix is invalid.
	 */
	public function register(): void {
		$foundationPrefix = $this->foundationPrefix();
		$commandPrefix    = $this->config->get('wpcli.command_prefix')
			?? $foundationPrefix;

		$this->container->mergeArrayVar(self::COMMANDS, []);
		$this->container->when(CommandPrefix::class)
			->needs('$value')
			->give($commandPrefix);
		$this->container->singleton(CommandPrefix::class);

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
