<?php declare(strict_types=1);

namespace StellarWP\Foundation\View;

use InvalidArgumentException;
use RuntimeException;
use StellarWP\Foundation\View\Contracts\DirectoryAwareView;
use StellarWP\Foundation\View\Exceptions\ViewNotFoundException;

/**
 * Renders relative PHP views while keeping every resolved file inside its configured directory.
 */
final readonly class PhpView implements DirectoryAwareView
{
	private const array RESERVED_DATA_KEYS = [
		'foundationViewPath',
		'foundationViewData',
	];

	private string $directory;

	/**
	 * @throws InvalidArgumentException When the directory does not exist, is unreadable, or is not a directory.
	 */
	public function __construct(string $directory) {
		$resolved = realpath($directory);

		if ($resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
			throw new InvalidArgumentException(sprintf('The view directory "%s" must exist and be readable.', $directory));
		}

		$this->directory = $resolved;
	}

	/**
	 * {@inheritDoc}
	 */
	public function withDirectory(string $directory): static {
		return new self($directory);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws InvalidArgumentException When the view name is invalid or the data contains a reserved key.
	 * @throws RuntimeException         When the view leaves output buffering in an invalid state.
	 * @throws ViewNotFoundException    When the view does not exist, is unreadable, or resolves outside the configured directory.
	 * @throws \Throwable               When the view itself throws.
	 */
	public function render(string $name, array $data = []): string {
		foreach (self::RESERVED_DATA_KEYS as $reservedKey) {
			if (array_key_exists($reservedKey, $data)) {
				throw new InvalidArgumentException(sprintf('View data key "%s" is reserved by PhpView.', $reservedKey));
			}
		}

		$path                = $this->resolve($name);
		$bufferLevel         = ob_get_level();
		$renderBufferLevel   = $bufferLevel + 1;
		$renderBufferTouched = false;

		ob_start(static function () use (&$renderBufferTouched): string {
			$renderBufferTouched = true;

			return '';
		});

		try {
			self::renderFile($path, $data);

			if ($renderBufferTouched || ob_get_level() !== $renderBufferLevel) {
				throw new RuntimeException(sprintf('The view "%s" must leave output buffering unchanged.', $name));
			}

			$output = ob_get_clean();

			if ($output === false) {
				throw new RuntimeException(sprintf('The output buffer for view "%s" could not be read.', $name));
			}

			return $output;
		} finally {
			self::discardBuffersAbove($bufferLevel);
		}
	}

	/**
	 * Render a PHP file in an isolated static scope with the supplied view data.
	 *
	 * @param array<string, mixed> $foundationViewData
	 */
	private static function renderFile(string $foundationViewPath, array $foundationViewData): void {
		extract($foundationViewData, EXTR_SKIP);

		require $foundationViewPath;
	}

	/**
	 * Remove buffers opened while rendering without closing a caller-owned buffer.
	 *
	 * PHP cannot remove a buffer created without PHP_OUTPUT_HANDLER_REMOVABLE.
	 */
	private static function discardBuffersAbove(int $bufferLevel): void {
		while (ob_get_level() > $bufferLevel) {
			$status = ob_get_status();

			if (($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0 || ! ob_end_clean()) {
				return;
			}
		}
	}

	/**
	 * Resolve a relative view name to a readable PHP file inside the configured directory.
	 *
	 * @throws InvalidArgumentException When the view name is empty, absolute, or traverses parent directories.
	 * @throws ViewNotFoundException    When the view cannot be safely resolved and read.
	 */
	private function resolve(string $name): string {
		$this->validateName($name);

		$relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name) . '.php';
		$path     = realpath($this->directory . DIRECTORY_SEPARATOR . $relative);

		if ($path === false || ! is_file($path) || ! is_readable($path) || ! $this->contains($path)) {
			throw new ViewNotFoundException(sprintf('The view "%s" could not be found in "%s".', $name, $this->directory));
		}

		return $path;
	}

	/**
	 * Reject names that could select files outside the configured directory.
	 *
	 * @throws InvalidArgumentException When the name is empty, absolute, or contains a parent-directory segment.
	 */
	private function validateName(string $name): void {
		if (
			trim($name) === ''
			|| str_contains($name, "\0")
			|| str_starts_with($name, '/')
			|| str_starts_with($name, '\\')
			|| preg_match('/^[A-Za-z]:[\\\\\/]/', $name) === 1
		) {
			throw new InvalidArgumentException('View names must be non-empty relative paths.');
		}

		$segments = preg_split('#[\\\\/]#', $name);

		if ($segments === false || in_array('..', $segments, true)) {
			throw new InvalidArgumentException('View names cannot traverse parent directories.');
		}
	}

	/**
	 * Determine whether a canonical file path remains inside the canonical view directory.
	 */
	private function contains(string $path): bool {
		$directory = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR;

		if (DIRECTORY_SEPARATOR === '\\') {
			return str_starts_with(strtolower($path), strtolower($directory));
		}

		return str_starts_with($path, $directory);
	}
}
