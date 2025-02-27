<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;
use Nadybot\Core\Types\ParamAttribute;
use ReflectionParameter;

/**
 * This parameter must be one of a list of string values
 * The first value is the one to show in the help page
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Str implements ParamAttribute {
	/** @var list<string> */
	public array $values = [];

	public function __construct(string $value, string ...$values) {
		$this->values = array_values(array_unique(array_merge([$value], array_values($values))));
	}

	/** {@inheritDoc} */
	public function renderParameter(ReflectionParameter $param): string {
		return $this->values[0];
	}

	/** {@inheritDoc} */
	public function getRegexp(): string {
		return implode('|', array_map('preg_quote', $this->values));
	}
}
