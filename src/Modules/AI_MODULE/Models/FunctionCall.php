<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/** A function call requested by the model. */
class FunctionCall {
	use StringableTrait;

	/**
	 * @param string $name      The name of the function to call
	 * @param string $arguments The JSON-encoded function arguments
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $arguments,
	) {
	}
}
