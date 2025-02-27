<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;
use ReflectionParameter;

/**
 * This parameter must be one of a list of string values
 * The help page will show all possible values
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class StrChoice extends Str {
	/** {@inheritDoc} */
	public function renderParameter(ReflectionParameter $param): string {
		return implode('|', $this->values);
	}
}
