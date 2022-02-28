<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;
use stdClass;

class WeatherData extends JSONDataModel {
	public Instant $instant;
	public stdClass $next_1_hours;
	public stdClass $next_6_hours;
}
