<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This parameter is a single word, without space */
#[Attribute(Attribute::TARGET_PARAMETER)]
class WordStr extends AbstractParamAttribute {
	/** {@inheritDoc} */
	public function getRegexp(): string {
		return '[^ ]+';
	}
}
