<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

/**
 * Add a prologue to the help page where this command is displayed.
 * A prologue is an intorductory text at the top of a help page.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Prologue {
	public function __construct(
		public string $text,
	) {
	}
}
