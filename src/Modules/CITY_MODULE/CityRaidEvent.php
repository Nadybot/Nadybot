<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Event;

abstract class CityRaidEvent extends Event {
	public const EVENT_MASK = "cityraid(*)";

	public int $wave;
}
