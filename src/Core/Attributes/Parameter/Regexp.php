<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This string needs to match the given regular expression */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Regexp extends AbstractParamAttribute {
	public function __construct(
		public string $value,
		?string $example=null,
	) {
		parent::__construct($example);
	}

	/** {@inheritDoc} */
	public function getRegexp(): string {
		return $this->value;
	}
}
