<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/** A tool call requested by the model. */
class ToolCall {
	use StringableTrait;

	/**
	 * @param FunctionCall $function The function and parameters to call
	 * @param string       $type     Always `"function"`
	 * @param string       $id       The unique identifier for this call
	 */
	public function __construct(
		public readonly FunctionCall $function,
		public readonly string $type,
		public readonly string $id,
	) {
	}
}
