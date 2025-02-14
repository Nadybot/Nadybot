<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This string must only consist of digits */
#[Attribute(Attribute::TARGET_PARAMETER)]
class NumberStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return "\d+";
	}
}
