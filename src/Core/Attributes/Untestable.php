<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This method handles a command that will never be auto-testable */
#[Attribute(Attribute::TARGET_METHOD)]
class Untestable {
	public function __construct() {
	}
}
