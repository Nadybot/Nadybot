<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This method will be exposed to the AI as a tool */
#[Attribute(Attribute::TARGET_METHOD)]
class ExposeToAI {
	public function __construct(
		public readonly string $name
	) {
	}
}
