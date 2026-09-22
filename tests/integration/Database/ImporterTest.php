<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Import\DatasetImporter;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Import\EntryRepository;

final class ImporterTest extends DatabaseTestCase
{
	public function test_application_service_replaces_multiple_batches_and_repository_uses_native_query_builder(): void {
		$this->container->when(EntryRepository::class)->needs('$tableName')->give($this->suffix);
		$records = array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Imported'], range(1, 501));
		self::assertSame(501, $this->container->get(DatasetImporter::class)->replace($records));
		self::assertSame(501, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->table));
		self::assertCount(50, $this->container->get(EntryRepository::class)->findByName('Imported'));
	}

	public function test_a_failure_in_the_second_batch_restores_the_original_dataset(): void {
		$this->container->when(EntryRepository::class)->needs('$tableName')->give($this->suffix);
		$records   = array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Imported'], range(1, 500));
		$records[] = ['id' => 1, 'name' => 'Duplicate in second batch'];

		try {
			$this->container->get(DatasetImporter::class)->replace($records);
			self::fail('Expected a second-batch constraint violation.');
		} catch (UniqueConstraintViolationException) {
			$this->assertOriginal();
		}
	}
}
