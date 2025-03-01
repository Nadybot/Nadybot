<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** Accept API requests for a given HTTP verb and path */
#[Attribute(Attribute::TARGET_METHOD)]
class HttpVerb {
	public function __construct(
		public readonly string $type,
		public readonly string $path
	) {
	}
}
