<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** The space in front of this parameter is optional */
#[Attribute(Attribute::TARGET_PARAMETER)]
class SpaceOptional {
	public function __construct() {
	}
}
