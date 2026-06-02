<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\WPCli;

use WP_CLI\Loggers\Regular;

final class TestWpCliLogger extends Regular
{
	/**
	 * @var list<string>
	 */
	public array $infoMessages = [];

	/**
	 * @var list<string>
	 */
	public array $successMessages = [];

	/**
	 * @var list<string>
	 */
	public array $warningMessages = [];

	/**
	 * @var list<string>
	 */
	public array $debugMessages = [];

	/**
	 * @var list<bool|string>
	 */
	public array $debugGroups = [];

	/**
	 * @var list<string>
	 */
	public array $errorLines = [];

	public function __construct() {
		parent::__construct(false);
	}

	public function info($message): void {
		$this->infoMessages[] = $message;
	}

	public function success($message): void {
		$this->successMessages[] = $message;
	}

	public function warning($message): void {
		$this->warningMessages[] = $message;
	}

	public function debug($message, $group = false): void {
		$this->debugMessages[] = $message;
		$this->debugGroups[]   = $group;
	}

	/**
	 * @param list<string> $messageLines
	 */
	public function error_multi_line($messageLines): void {
		$this->errorLines = $messageLines;
	}
}
