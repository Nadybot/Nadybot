<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class HttpPost {
	public string $type = "post";

	public function __construct(public string $value) {
	}
}
