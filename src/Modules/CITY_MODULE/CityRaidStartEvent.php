<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

class CityRaidStartEvent extends CityRaidEvent {
	public const EVENT_MASK = 'cityraid(start)';

	public function __construct() {
		$this->wave = 0;
		$this->type = self::EVENT_MASK;
	}
}
