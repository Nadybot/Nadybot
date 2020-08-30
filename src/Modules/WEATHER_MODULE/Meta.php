<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class Meta extends JSONDataModel {
	public string $updated_at;
	public Units $units;
}
