<?php declare(strict_types=1);

if (! function_exists('fastcgi_finish_request')) {
	function fastcgi_finish_request(): bool {
		$GLOBALS['foundation_shutdown_calls'][] = 'fastcgi';

		if ($GLOBALS['foundation_shutdown_fastcgi_failure'] ?? false) {
			throw new RuntimeException('Expected response-finishing failure.');
		}

		return ! ($GLOBALS['foundation_shutdown_fastcgi_false'] ?? false);
	}
}

if (! function_exists('litespeed_finish_request')) {
	function litespeed_finish_request(): bool {
		$GLOBALS['foundation_shutdown_calls'][] = 'litespeed';

		return true;
	}
}
