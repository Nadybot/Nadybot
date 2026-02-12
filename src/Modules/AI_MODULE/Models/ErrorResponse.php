<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/**
 * For requests that failed with a non-HTTP error, this will contain
 * more information on the cause of the failure.
 */
class ErrorResponse {
	use StringableTrait;

	public function __construct(
		public readonly ErrorObject $error,
	) {
	}
}
