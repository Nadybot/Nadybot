<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This function should be called for setup */
#[Attribute(Attribute::TARGET_METHOD)]
class Setup {
	public function __construct() {
	}
}
