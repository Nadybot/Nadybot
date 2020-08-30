<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Timeseries extends JSONDataModel {
	public string $time;
	public WeatherData $data;
}
