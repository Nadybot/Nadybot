<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** This method handles authentication itself */
#[Attribute(Attribute::TARGET_METHOD)]
class HttpOwnAuth {
	public function __construct() {
	}
}
