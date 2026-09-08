<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli;

use RuntimeException;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;

/**
 * Runs Foundation tooling with commands contributed by project and package providers.
 */
final class Application extends SymfonyApplication
{
	/**
	 * Assemble commands without silently replacing another command or alias.
	 *
	 * @param iterable<Command> $commands Commands contributed before application construction.
	 *
	 * @throws RuntimeException When a command name or alias is already registered.
	 */
	public function __construct(iterable $commands = []) {
		parent::__construct('Foundation');

		foreach ($commands as $command) {
			foreach (array_merge([$command->getName()], $command->getAliases()) as $name) {
				if ($name !== null && $this->has($name)) {
					throw new RuntimeException(sprintf('Command identifier "%s" conflicts between %s and %s.', $name, $this->get($name)::class, $command::class));
				}
			}

			$this->addCommands([$command]);
		}
	}
}
