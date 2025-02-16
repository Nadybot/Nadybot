<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class can be used as an event */
#[Attribute(Attribute::TARGET_CLASS)]
class Event {
	public function __construct(
		public readonly string $mask,
	) {
	}
}
