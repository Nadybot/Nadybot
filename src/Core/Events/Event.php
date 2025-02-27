<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\StringableTrait;
use Nadybot\Core\Types\{DoNotSerializePublicFunctions, EventInterface};
use Stringable;

/**
 * This is an abstract base class for events that can have multiple event types,
 * based on a given value `$type`. This is only needed if the event itself doesn't
 * have a fixed type, but the type depends on other data.
 */
abstract class Event implements Stringable, DoNotSerializePublicFunctions, EventInterface {
	use StringableTrait;

	/** @param string $type The event type */
	public function __construct(
		public string $type,
	) {
	}

	/** {@inheritDoc} */
	public function getEvent(): string {
		return $this->type;
	}

	/**
	 * Set this event's type
	 *
	 * @return string The new event type
	 */
	protected function setEvent(string $type): string {
		return $this->type = $type;
	}
}
