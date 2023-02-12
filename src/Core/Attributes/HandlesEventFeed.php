<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE|Attribute::TARGET_CLASS)]
class HandlesEventFeed {
	public function __construct(
		public string $room,
	) {
	}
}
