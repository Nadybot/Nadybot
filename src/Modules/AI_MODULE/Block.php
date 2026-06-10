<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

/**
 * Represents a contiguous conversational block starting with a user message.
 *
 * The block spans from $startIndex to $endIndex (both inclusive),
 * i.e. all entries $startIndex .. $endIndex belong to the block.
 */
final class Block {
	public function __construct(
		/** The index of the first entry (the user message). */
		public readonly int $startIndex,
		/** The index of the last entry of the block. */
		public readonly int $endIndex,
	) {
	}

	/** Number of entries in this block. */
	public function length(): int {
		return $this->endIndex - $this->startIndex + 1;
	}
}
