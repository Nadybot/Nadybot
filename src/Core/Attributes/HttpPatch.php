<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class HttpPatch extends HttpVerb {
	public string $type = "patch";
}
