<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

/**
 * Add an epilogue to the help page where this command is displayed.
 * An epilogue is an text at the top of a help page.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Epilogue {
	public function __construct(
		public string $text,
	) {
	}
}
