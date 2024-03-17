<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class MigrationOrder {
	public function __construct(
		public int $dateTime,
	) {
	}
}
