<?php declare(strict_types=1);

/**
 * Convert environment variables into a config array for use in log tests.
 *
 * @see \StellarWP\Foundation\Tests\TestCase::setUp()
 * @see \Adbar\Dot
 * @see phpunit.xml.dist
 */
return [
	'log' => [
		'level'    => $_ENV['TEST_LOG_LEVEL'] ?? 'debug',
		'channel'  => $_ENV['TEST_LOG_CHANNEL'] ?? 'null',
		'channels' => [
			'errorlog' => [],
			'console'  => [
				'with' => [
					'stream' => 'php://stdout',
				],
			],
			'stack'    => [
				'with' => [
					'stream' => 'php://stdout',
				],
			],
		],
	],
];
