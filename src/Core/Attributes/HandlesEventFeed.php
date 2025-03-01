<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class handles the event-food of the given Highway room */
#[Attribute(Attribute::IS_REPEATABLE|Attribute::TARGET_CLASS)]
class HandlesEventFeed {
	public function __construct(
		public string $room,
	) {
	}
}
