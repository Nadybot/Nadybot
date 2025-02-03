<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\Ability;

#[Attribute(Attribute::TARGET_PARAMETER)]
class AbilityStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return Ability::getNameRegexp();
	}
}
