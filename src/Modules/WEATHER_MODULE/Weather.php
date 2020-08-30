<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Weather extends JSONDataModel {
	public string $type;
	public Geometry $geometry;
	public Properties $properties;
}
