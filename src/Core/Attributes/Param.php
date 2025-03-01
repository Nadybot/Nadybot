<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\ParamType;

/** This class has parameters to configure it */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Param {
	public function __construct(
		public ?string $name=null,
		public ?ParamType $type=null,
	) {
	}
}
