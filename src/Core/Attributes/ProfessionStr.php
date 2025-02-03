<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Safe;
use Nadybot\Core\Types\{ParamAttribute, Profession};
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ProfessionStr implements ParamAttribute {
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
		return Profession::getNameRegexp();
	}
}
