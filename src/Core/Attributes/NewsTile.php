<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class NewsTile {
	public function __construct(
		public string $name,
		public string $description,
		public string $example
	) {
	}
}
