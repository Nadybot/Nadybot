<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

class FunctionPropertyBoolean extends FunctionProperty {
	use StringableTrait;

	public function __construct(
		public readonly string $description,
	) {
		parent::__construct('boolean');
	}
}
