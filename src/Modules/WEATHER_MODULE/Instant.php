<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Instant extends JSONDataModel {
	public InstantDetails $details;
}
