<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Safe;
use Nadybot\Core\Types\ParamAttribute as TypesParamAttribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class PDuration implements TypesParamAttribute {
	public function __construct(
		public ?string $example=null
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

	public function getRegexp(): string {
		return "(?:(?:,?\s*\d+(?:yr?|years?|m|months?|w|weeks?|d|days?|h|hrs?|hours?|m|mins?|s|secs?))+|[1-9]\d*)";
	}
}
