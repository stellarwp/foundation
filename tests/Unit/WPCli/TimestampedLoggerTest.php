<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\WPCli;

use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\TestWpCliLogger;
use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\WPCli\TimestampedLogger;

final class TimestampedLoggerTest extends TestCase
{
	public function test_it_prepends_timestamps_to_logger_messages(): void {
		$wpLogger = new TestWpCliLogger();
		$logger   = new TimestampedLogger($wpLogger, 'Y-m-d H:i:s e', 'UTC');

		$logger->info('Information.');
		$logger->success('Success.');
		$logger->warning('Warning.');
		$logger->debug('Debug.', 'group');
		$logger->error_multi_line(['First.', 'Second.']);

		$this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\] Information\.$/', $wpLogger->infoMessages[0]);
		$this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\] Success\.$/', $wpLogger->successMessages[0]);
		$this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\] Warning\.$/', $wpLogger->warningMessages[0]);
		$this->assertSame('group', $wpLogger->debugGroups[0]);
		$this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\] Debug\.$/', $wpLogger->debugMessages[0]);
		$this->assertCount(2, $wpLogger->errorLines);
		$this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\] First\.$/', $wpLogger->errorLines[0]);
		$this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\] Second\.$/', $wpLogger->errorLines[1]);
	}
}
