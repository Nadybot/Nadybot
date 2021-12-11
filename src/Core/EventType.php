<?php declare(strict_types=1);

namespace Nadybot\Core;

class EventType {
	/** The name of the event */
	public string $name;

	/** The optional description, explaining when it occurs */
	public ?string $description = null;
}
