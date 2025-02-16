<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'cityraid(*)')]
abstract class CityRaidEvent {
	public function __construct(
		public int $wave,
	) {
	}
}
