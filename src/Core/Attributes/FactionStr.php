<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\Faction;

#[Attribute(Attribute::TARGET_PARAMETER)]
class FactionStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return Faction::getNameRegexp();
	}
}
