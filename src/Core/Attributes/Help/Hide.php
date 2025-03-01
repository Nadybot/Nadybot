<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

/** Don't show this command invocation on the help page for this command */
#[Attribute(Attribute::TARGET_METHOD)]
class Hide {
	public function __construct() {
	}
}
