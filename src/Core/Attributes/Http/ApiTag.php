<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** An additional OpenAPI tag for this API endpoint */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiTag {
	public function __construct(public string $tag) {
	}
}
