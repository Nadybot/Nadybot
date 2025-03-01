<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class is a migration with the given order */
#[Attribute(Attribute::TARGET_CLASS)]
class Migration {
	public function __construct(
		public float $order,
		public bool $shared=false,
	) {
	}
}
