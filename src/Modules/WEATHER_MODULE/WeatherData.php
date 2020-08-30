<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class WeatherData extends JSONDataModel {
	public Instant $instant;
	public object $next_1_hours;
	public object $next_6_hours;
}
