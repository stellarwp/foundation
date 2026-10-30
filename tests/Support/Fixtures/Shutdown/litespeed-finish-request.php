<?php declare(strict_types=1);

if (! function_exists('litespeed_finish_request')) {
	function litespeed_finish_request(): bool {
		$GLOBALS['foundation_shutdown_calls'][] = 'litespeed';

		return true;
	}
}
