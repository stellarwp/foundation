<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log;

use lucatume\DI52\ContainerException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Tests\TestCase;

final class InvalidLoggerTest extends TestCase
{
	protected function setUp(): void {
		$_ENV['TEST_LOG_CHANNEL'] = 'invalid';

		parent::setUp();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_throws_for_invalid_log_channels(): void {
		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('invalid log channel. Valid options are: console,errorlog,stack,null');

		$this->container->get(LoggerInterface::class);
	}
}
