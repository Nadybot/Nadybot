<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Str {
	/** @var string[] */
	public array $values = [];

	public function __construct(string $value, string ...$values) {
		$this->values = array_unique(array_merge([$value], $values));
	}

	public function renderParameter(ReflectionParameter $param): string {
		return $this->values[0];
	}
}
