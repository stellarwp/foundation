<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

use StellarWP\Foundation\Container\Contracts\Provider;

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

	private function registerCommands(): void {
		$commands = $this->container->get(self::COMMANDS);

		foreach ($commands as $command) {
			if (! $command instanceof Command) {
				continue;
			}

			$command->register();
		}
	}
}
