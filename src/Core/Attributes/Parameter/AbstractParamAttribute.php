<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Nadybot\Core\Safe;
use Nadybot\Core\Types\ParamAttribute;
use ReflectionParameter;

abstract class AbstractParamAttribute implements ParamAttribute {
	public function __construct(
		protected readonly ?string $example=null
	) {
	}

	public function renderParameter(ReflectionParameter $param): string {
		if (isset($this->example)) {
			return $this->example;
		}
		return '&lt;' . Safe::pregReplaceCallback(
			'/([A-Z]+)/',
			static function (array $matches): string {
				return ' ' . strtolower($matches[1]);
			},
			$param->getName(),
		) . '&gt;';
	}

	abstract public function getRegexp(): string;
}
