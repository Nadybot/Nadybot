<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Units extends JSONDataModel {
	public string $air_pressure_at_sea_level;
	public string $air_temperature;
	public string $cloud_area_fraction;
	public string $precipitation_amount;
	public string $relative_humidity;
	public string $wind_from_direction;
	public string $wind_speed;
}
