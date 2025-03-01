<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Nadybot\Core\Safe;
use Nadybot\Core\Types\ParamAttribute;
use ReflectionParameter;

/**
 * This is an abstract base class that implements `ParamAttribute` to allow
 * attributes to be used as command parameters in an easy way.
 * The only thing that really needs to be implemented it the
 * `getRegexp()` function.
 */
abstract class AbstractParamAttribute implements ParamAttribute {
	/** @param null|string $example If set, don't use <paramName> as name, but this string */
	public function __construct(
		protected readonly ?string $example=null
	) {
	}

	/** {@inheritDoc} */
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

	/** {@inheritDoc} */
	abstract public function getRegexp(): string;
}
