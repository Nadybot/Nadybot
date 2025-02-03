<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\Profession;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ProfessionStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return Profession::getNameRegexp();
	}
}
