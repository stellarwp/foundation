<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\Php\ValueObjects;

use PhpParser\Node\Expr\Array_ as ArrayExpression;

/**
 * Represents the editable registration list inside a mergeArrayVar() call.
 */
final readonly class MergeArrayVarTarget
{
	public function __construct(
		public ArrayExpression $registrationList,
		public string $containerExpression
	) {
	}
}
