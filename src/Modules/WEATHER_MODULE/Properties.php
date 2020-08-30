<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Properties extends JSONDataModel {
	public Meta $meta;
	/** @var \Nadybot\Modules\WEATHER_MODULE\Timeseries[] */
	public array $timeseries;
}
