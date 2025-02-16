<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'cityraid(start)')]
class CityRaidStartEvent extends CityRaidEvent {
	public function __construct() {
		parent::__construct(wave: 0);
	}
}
