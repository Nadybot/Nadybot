<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** Accept PUT API requests for a given path */
#[Attribute(Attribute::TARGET_METHOD)]
class HttpPut extends HttpVerb {
	public function __construct(string $path) {
		parent::__construct(type: 'put', path: $path);
	}
}
