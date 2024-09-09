<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\ParamAttribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Str implements ParamAttribute {
	/** @var list<string> */
	public array $values = [];

	public function __construct(string $value, string ...$values) {
		$this->values = array_values(array_unique(array_merge([$value], array_values($values))));
	}

	public function renderParameter(ReflectionParameter $param): string {
		return $this->values[0];
	}

	public function getRegexp(): string {
		return implode('|', array_map('preg_quote', $this->values));
	}
}
