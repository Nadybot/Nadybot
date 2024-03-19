<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

abstract class Event implements Stringable {
	use StringableTrait;

	/** @var string */
	public const EVENT_MASK = "*";

	public string $type;
}
