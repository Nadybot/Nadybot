<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiTag {
	public function __construct(public string $tag) {
	}
}
