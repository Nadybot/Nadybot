<?php declare(strict_types=1);

namespace Nadybot\Core;

use stdClass;

abstract class Event extends stdClass {
	/** @var string */
	public const EVENT_MASK = "*";

	public string $type;
}
