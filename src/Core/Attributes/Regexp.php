<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Regexp extends AbstractParamAttribute {
	public function __construct(
		public string $value,
		?string $example=null,
	) {
		parent::__construct($example);
	}

	public function getRegexp(): string {
		return $this->value;
	}
}
