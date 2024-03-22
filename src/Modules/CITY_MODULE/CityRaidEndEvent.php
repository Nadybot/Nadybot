<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

class CityRaidEndEvent extends CityRaidEvent {
	public const EVENT_MASK = 'cityraid(end)';

	public function __construct() {
		$this->wave = 9;
		$this->type = self::EVENT_MASK;
	}
}
