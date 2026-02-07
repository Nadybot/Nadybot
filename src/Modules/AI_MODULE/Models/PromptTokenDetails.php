<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/** Breakdown of tokens used in the prompt. */
class PromptTokenDetails {
	use StringableTrait;

	/** @param int $cached_tokens Cached tokens present in the prompt. */
	public function __construct(
		public readonly int $cached_tokens,
	) {
	}
}
