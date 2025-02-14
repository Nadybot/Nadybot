<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Events\Event;

/** This class emits and provides a given event */
#[Attribute(Attribute::IS_REPEATABLE|Attribute::TARGET_CLASS)]
class ProvidesEvent {
	public function __construct(
		public string $event,
		public ?string $desc=null
	) {
		if (class_exists($event, false) && is_subclass_of($event, Event::class)) {
			$this->event = $event::EVENT_MASK;
		}
	}
}
