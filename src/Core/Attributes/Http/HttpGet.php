<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** Accept GET API requests for a given path */
#[Attribute(Attribute::TARGET_METHOD)]
class HttpGet extends HttpVerb {
	public function __construct(string $path) {
		parent::__construct(type: 'get', path: $path);
	}
}
