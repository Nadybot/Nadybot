<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class HttpVerb {
	public string $type = "none";

	public function __construct(public string $value) {
	}
}
