<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

class CityRaidWaveEvent extends CityRaidEvent {
	public const EVENT_MASK = 'cityraid(wave)';

	public function __construct(
		public int $wave,
	) {
		$this->type = self::EVENT_MASK;
	}
}
