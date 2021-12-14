<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(0)]
class ClassSpec {
	public function __construct(
		public string $name,
		public string $description
	) {
	}
}
