<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Str {
	public function __construct(public string $value) {
	}
}
