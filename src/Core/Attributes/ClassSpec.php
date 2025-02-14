<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/**
 * This is the base attribute class for all attributes that are used
 * to define a special kind of class that can be used with
 * `Util::getClassSpecFromClass()`
 */
#[Attribute(0)]
class ClassSpec {
	public function __construct(
		public string $name,
		public string $description
	) {
	}
}
