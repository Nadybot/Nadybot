<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class HttpPost extends HttpVerb {
	public string $type = "post";
}
