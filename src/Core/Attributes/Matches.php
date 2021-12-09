<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Matches {
	public function __construct(public string $value) {
	}
}
