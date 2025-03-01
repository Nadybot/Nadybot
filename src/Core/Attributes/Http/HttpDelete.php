<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** Accept DELETE API requests for a given path */
#[Attribute(Attribute::TARGET_METHOD)]
class HttpDelete extends HttpVerb {
	public function __construct(string $path) {
		parent::__construct(type: 'delete', path: $path);
	}
}
