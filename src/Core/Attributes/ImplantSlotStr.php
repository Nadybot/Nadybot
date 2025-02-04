<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\ImplantSlot;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ImplantSlotStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return ImplantSlot::getParamRegexp();
	}
}
