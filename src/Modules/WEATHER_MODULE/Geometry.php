<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Geometry extends JSONDataModel {
	public string $type;
	/** @var array<float,float,int> */
	public array $coordinates;
}
