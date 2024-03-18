<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Event;

abstract class CloakEvent extends Event {
	public const EVENT_MASK = "cloak(*)";

	/** Name of the character lowering/raising the cloak */
	public string $player;
}
