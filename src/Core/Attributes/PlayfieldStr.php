<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\Playfield;

#[Attribute(Attribute::TARGET_PARAMETER)]
class PlayfieldStr extends AbstractParamAttribute {
	public function __construct(
		private bool $allowLong=false,
		?string $example=null
	) {
		parent::__construct($example);
	}

	public function getRegexp(): string {
		return $this->allowLong ? Playfield::getNameRegexp() : Playfield::getShortRegexp();
	}
}
