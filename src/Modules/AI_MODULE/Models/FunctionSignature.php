<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

class FunctionSignature {
	use StringableTrait;

	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly FunctionParameters $parameters,
	) {
	}
}
