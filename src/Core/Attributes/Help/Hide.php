<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Hide {
	public function __construct() {
	}
}
