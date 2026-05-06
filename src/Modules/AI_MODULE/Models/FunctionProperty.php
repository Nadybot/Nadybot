<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

abstract class FunctionProperty {
	use StringableTrait;

	public function __construct(
		public readonly string $type,
	) {
	}
}
