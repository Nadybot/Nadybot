<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/**
 * For requests that failed with a non-HTTP error, this will contain
 * more information on the cause of the failure.
 */
class ErrorObject {
	use StringableTrait;

	/**
	 * @param string  $message A human-readable error message.
	 * @param ?string $code    A machine-readable error code.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $message,
		public readonly ?string $code=null,
		public readonly ?string $param=null,
	) {
	}
}
