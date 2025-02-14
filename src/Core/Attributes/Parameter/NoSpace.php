<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** There must be no space between this parameter and the one before */
#[Attribute(Attribute::TARGET_PARAMETER)]
class NoSpace {
	public function __construct() {
	}
}
