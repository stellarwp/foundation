<?php declare(strict_types=1);

namespace Plugin\Tooling\Commands;

use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check a project's PHP file for syntax errors.
 */
final class Lint_Command extends Command
{
	/**
	 * Receive the process runner supplied by Foundation or the project provider.
	 */
	public function __construct(private readonly ProcessRunner $process) {
		parent::__construct('tooling:lint');
	}

	/**
	 * Select the PHP file to check.
	 */
	protected function configure(): void {
		$this->addArgument('file', InputArgument::REQUIRED, 'PHP file to check.');
	}

	/**
	 * Report PHP's syntax check and preserve its exit status.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		return $this->process->run([PHP_BINARY, '-l', (string) $input->getArgument('file')]);
	}
}
