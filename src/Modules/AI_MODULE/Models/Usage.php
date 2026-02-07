<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/** Usage statistics for the completion request. */
class Usage {
	use StringableTrait;

	/**
	 * @param int                         $prompt_tokens             Number of tokens in the prompt.
	 * @param int                         $completion_tokens         Number of tokens in the generated completion.
	 * @param int                         $total_tokens              Total number of tokens used in the request (prompt + completion).
	 * @param null|PromptTokenDetails     $prompt_tokens_details     Breakdown of tokens used in the prompt.
	 * @param null|CompletionTokenDetails $completion_tokens_details Breakdown of tokens used in a completion.
	 */
	public function __construct(
		public readonly int $prompt_tokens,
		public readonly int $completion_tokens,
		public readonly int $total_tokens,
		public readonly ?PromptTokenDetails $prompt_tokens_details=null,
		public readonly ?CompletionTokenDetails $completion_tokens_details=null,
	) {
	}
}
