<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Regexp {
	public function __construct(
		public string $value,
		public ?string $example=null,
	) {
	}
}
