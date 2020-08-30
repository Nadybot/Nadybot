<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;

class NominatimAddress extends JSONDataModel {
	public string $suburb;
	public string $town;
	public string $county;
	public string $state;
	public string $postcode;
	public string $country;
	public string $country_code;
}
