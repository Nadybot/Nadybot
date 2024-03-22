<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class StrChoice extends Str {
	public function renderParameter(ReflectionParameter $param): string {
		return implode('|', $this->values);
	}
}
