<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/**
 * Represents an error from the translion Api
 */
class TranslateError {
	use StringableTrait;

	/**
	 * @param string $code       The error code (e.g. "INVALID_API_KEY")
	 * @param string $error      The error message (e.g. "Invalid API key")
	 * @param string $request_id The unique ID of the request that caused the error
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $error,
		public readonly string $request_id,
	) {
	}
}
