<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class NumberStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return "\d+";
	}
}
