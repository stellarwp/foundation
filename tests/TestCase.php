<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests;

use Adbar\Dot;
use Mockery;
use ReflectionObject;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Providable;
use StellarWP\Foundation\Log\LogProvider;
use StellarWP\Foundation\Tests\Support\Traits\WithDataDir;

class TestCase extends \PHPUnit\Framework\TestCase
{
	use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
	use WithDataDir;

	public const string TEST_DIR    = 'test_dir';
	public const string DATA_DIR    = 'data_dir';
	public const string FIXTURE_DIR = 'fixture_dir';

	protected Container $container;

	/**
	 * @var array<class-string<Providable>>
	 */
	private array $providers = [
		LogProvider::class,
	];

	/**
	 * @var array<string, string|null>
	 */
	private static array $originalEnv = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::capture_test_environment();
	}

	protected function tearDown(): void {
		$this->closeMockery();
		$this->cleanup_temp_dirs();
		$this->reset_test_environment();

		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		$this->container = new ContainerAdapter(new \lucatume\DI52\Container());
		$this->container->bind(Container::class, $this->container);
		$this->container->bind(ContainerInterface::class, $this->container);
		$this->container->singleton(Dot::class, new Dot(require __DIR__ . '/config.php'));
		$this->container->singleton(self::TEST_DIR, __DIR__);
		$this->container->singleton(self::DATA_DIR, __DIR__ . '/_data/');
		$this->container->singleton(self::FIXTURE_DIR, fn (): string => dirname((string) (new ReflectionObject($this))->getFileName()) . '/fixtures');

		foreach ($this->providers as $provider) {
			$this->container->register($provider);
		}
	}

	/**
	 * Create a mock.
	 *
	 * @template T
	 *
	 * @param class-string<T> ...$args
	 *
	 * @phpstan-return T&Mockery\MockInterface&Mockery\LegacyMockInterface
	 */
	protected function mock(...$args): Mockery\MockInterface {
		return Mockery::mock(...$args); // @phpstan-ignore-line
	}

	private function reset_test_environment(): void {
		foreach (self::$originalEnv as $key => $value) {
			if ($value === null) {
				unset($_ENV[$key]);
				continue;
			}

			$_ENV[$key] = $value;
		}
	}

	private static function capture_test_environment(): void {
		foreach ([
			'ENVIRONMENT',
			'TEST_LOG_CHANNEL',
			'TEST_LOG_LEVEL',
		] as $key) {
			self::$originalEnv[$key] = $_ENV[$key] ?? null;
		}
	}
}
