<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\Definitions;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\TableEditor;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;

/**
 * Declare a relationship and the actions taken when its referenced row changes.
 */
final class ForeignKeyDefinition
{
	private ?string $referencedTable = null;
	/**
	 * @var non-empty-list<non-empty-string>|null
	 */
	private ?array $referencedColumns   = null;
	private ReferentialAction $onDelete = ReferentialAction::RESTRICT;
	private ReferentialAction $onUpdate = ReferentialAction::RESTRICT;

	/**
	 * Receive the logical constraint identity and local columns.
	 *
	 * @param non-empty-string                 $name
	 * @param non-empty-list<non-empty-string> $localColumns
	 *
	 * @internal Constructed by TableBlueprint::foreignKey().
	 *
	 * @throws InvalidArgumentException When the logical name is empty.
	 */
	public function __construct(
		private readonly string $name,
		private readonly array $localColumns,
	) {
		if (trim($name) === '') {
			throw new InvalidArgumentException('A foreign key must have a name.');
		}
	}

	/**
	 * Return the logical name used to resolve this constraint's historical identity.
	 *
	 * @internal
	 *
	 * @return non-empty-string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Reference historical, unprefixed application table and column names.
	 *
	 * @param non-empty-string $table
	 * @param non-empty-string $columns
	 *
	 * @throws InvalidArgumentException When local and referenced column counts differ.
	 */
	public function references(string $table, string ...$columns): self {
		if ($columns === [] || count($columns) !== count($this->localColumns)) {
			throw new InvalidArgumentException('A foreign key must reference one column for each local column.');
		}

		$this->referencedTable   = $table;
		$this->referencedColumns = array_values($columns);

		return $this;
	}

	/**
	 * Propagate referenced row deletion.
	 */
	public function cascadeOnDelete(): self {
		$this->onDelete = ReferentialAction::CASCADE;

		return $this;
	}

	/**
	 * Reject referenced row deletion.
	 */
	public function restrictOnDelete(): self {
		$this->onDelete = ReferentialAction::RESTRICT;

		return $this;
	}

	/**
	 * Clear the local columns on referenced row deletion.
	 */
	public function nullOnDelete(): self {
		$this->onDelete = ReferentialAction::SET_NULL;

		return $this;
	}

	/**
	 * Propagate referenced row key updates.
	 */
	public function cascadeOnUpdate(): self {
		$this->onUpdate = ReferentialAction::CASCADE;

		return $this;
	}

	/**
	 * Reject referenced row key updates.
	 */
	public function restrictOnUpdate(): self {
		$this->onUpdate = ReferentialAction::RESTRICT;

		return $this;
	}

	/**
	 * Clear the local columns on referenced row key updates.
	 */
	public function nullOnUpdate(): self {
		$this->onUpdate = ReferentialAction::SET_NULL;

		return $this;
	}

	/**
	 * Add the completed relationship to the Doctrine declaration.
	 *
	 * @internal Called by TableBlueprint::applyTo().
	 *
	 * @param non-empty-string $constraintName
	 *
	 * @throws InvalidArgumentException When references() has not completed the declaration.
	 */
	public function applyTo(TableEditor $editor, TableNameResolver $names, string $constraintName): void {
		if ($this->referencedTable === null || $this->referencedColumns === null) {
			throw new InvalidArgumentException('Complete the foreign key declaration with references().');
		}

		$editor->addForeignKeyConstraint(ForeignKeyConstraint::editor()
			->setUnquotedName($constraintName)
			->setUnquotedReferencingColumnNames(...$this->localColumns)
			->setUnquotedReferencedTableName($names->tableName($this->referencedTable))
			->setUnquotedReferencedColumnNames(...$this->referencedColumns)
			->setOnDeleteAction($this->onDelete)
			->setOnUpdateAction($this->onUpdate)
			->create());
	}
}
