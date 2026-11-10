<?php declare(strict_types=1);

namespace Plugin\Tooling\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display the application prefix supplied by the tooling provider.
 */
final class Prefix_Command extends Command
{
	/**
	 * Receive the configured application prefix.
	 */
	public function __construct(private readonly string $prefix) {
		parent::__construct('tooling:prefix');
	}

	/**
	 * Print the prefix for use in development scripts.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln($this->prefix);

		return Command::SUCCESS;
	}
}
