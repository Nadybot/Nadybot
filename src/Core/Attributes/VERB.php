<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This endpoint listens for HTTP requests */
#[Attribute(0)]
class VERB {
	public function __construct() {
	}
}
