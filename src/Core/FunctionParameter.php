<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Types\ParamType;

/** The name and specs of a function parameter */
class FunctionParameter {
	public function __construct(
		public string $name,
		public ParamType $type,
		public ?string $description=null,
		public bool $required=true,
	) {
	}
}
