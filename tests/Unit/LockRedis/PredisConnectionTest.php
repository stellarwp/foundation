<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\LockRedis;

use Predis\ClientException;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Response\ErrorInterface;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\LockRedis\Connections\PredisConnection;
use StellarWP\Foundation\Tests\TestCase;

final class PredisConnectionTest extends TestCase
{
	public function test_it_rejects_non_integer_eval_responses(): void {
		$connection = new PredisConnection($this->clientReturning(null));

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Predis returned an unexpected EVAL response.');

		$connection->evaluate('return 1', [], []);
	}

	public function test_it_rejects_non_integer_exists_responses(): void {
		$connection = new PredisConnection($this->clientReturning(null));

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Predis returned an unexpected EXISTS response.');

		$connection->exists('lock');
	}

	public function test_it_wraps_predis_exceptions(): void {
		$client  = $this->mock(ClientInterface::class);
		$command = $this->mock(CommandInterface::class);

		$client->shouldReceive('createCommand')->once()->andReturn($command);
		$client->shouldReceive('executeCommand')->once()->with($command)->andThrow(new ClientException('connection lost'));

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Predis could not execute the lock operation.');

		(new PredisConnection($client))->exists('lock');
	}

	public function test_it_rejects_predis_error_responses(): void {
		$error = $this->mock(ErrorInterface::class);
		$error->shouldReceive('getMessage')->once()->andReturn('ERR connection lost');

		$this->expectException(LockUnavailableException::class);
		$this->expectExceptionMessage('Predis could not execute the lock operation: ERR connection lost');

		(new PredisConnection($this->clientReturning($error)))->exists('lock');
	}

	private function clientReturning(mixed $result): ClientInterface {
		$client  = $this->mock(ClientInterface::class);
		$command = $this->mock(CommandInterface::class);

		$client->shouldReceive('createCommand')->once()->andReturn($command);
		$client->shouldReceive('executeCommand')->once()->with($command)->andReturn($result);

		return $client;
	}
}
