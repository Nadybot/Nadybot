<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;
use Nadybot\Core\Types\ImplantSlot;

/** This string argument must be a valid implant slot name */
#[Attribute(Attribute::TARGET_PARAMETER)]
class ImplantSlotStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return ImplantSlot::getParamRegexp();
	}
}
