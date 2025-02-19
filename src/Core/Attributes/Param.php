<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class has parameters to configure it */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Param {
	public function __construct(
		public ?string $name=null,
		public ?string $type=null,
	) {
	}
}
