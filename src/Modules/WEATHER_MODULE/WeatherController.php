<?php

namespace Budabot\Modules\WEATHER_MODULE;

use stdClass;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'weather',
 *		accessLevel = 'all',
 *		description = 'View Weather',
 *		help        = 'weather.txt'
 *	)
 */
class WeatherController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;

	/**
	 * Convert a stdClass to something more specific
	 *
	 * @param \stdClass $obj The object to typecast
	 * @param string $newClass The class to typecast into
	 */
	protected function castClass(stdClass $obj, $newClass) {
		return unserialize(
			sprintf(
				'O:%d:"%s"%s',
				\strlen($newClass),
				$newClass,
				strstr(strstr(serialize($obj), '"'), ':')
			)
		);
	}
	
	/**
	 * @HandlesCommand("weather")
	 * @Matches("/^weather (.+)$/i")
	 */
	public function weatherCommand($message, $channel, $sender, $sendto, $args) {
		$location = $args[1];
		$nominatim = $this->lookupLocation($location);
		if ($nominatim === null) {
			$msg = "Unable to find <highlight>$location<end>.";
			$sendto->reply($msg);
			return;
		}
		$weather = $this->lookupWeatherForCoords($nominatim->lat, $nominatim->lon);
		if ($weather === null) {
			$msg = "Unable to get weather information for <highlight>$location<end>.";
			$sendto->reply($msg);
			return;
		}
		$blob = $this->renderWeather($nominatim, $weather);
		$placeParts = explode(", ", $nominatim->display_name);
		$header = "The current weather for <highlight>{$placeParts[0]}<end>";
		if (count($placeParts) > 2 && $nominatim->address->country_code === "us") {
			$header .= ", " . $nominatim->address->state;
		} elseif (count($placeParts) > 1) {
			$header .= ", " . $nominatim->address->country;
		}
		$currentIcon = $weather->properties->timeseries[0]->data->next_1_hours->summary->symbol_code;
		$currentSummary = $this->iconToForecastSummary($currentIcon);
		$currentTemp = $weather->properties->timeseries[0]->data->instant->details->air_temperature;
		$tempUnit =  $this->nameToDegree($weather->properties->meta->units->air_temperature);
		$blob = $this->text->makeBlob("details", $blob, strip_tags($header));

		$msg = "$header: <highlight>{$currentTemp}{$tempUnit}<end>, ".
			"<highlight>{$currentSummary}<end> [{$blob}]";
		$sendto->reply($msg);
	}

	/**
	 * Lookup the coordinates of a location
	 *
	 * @param string $location Name of the place, country, city... any location
	 * @return \Budabot\Modules\WEATHER_MODULE\Nominatim|null
	 */
	public function lookupLocation($location) {
		$apiEndpoint = "https://nominatim.openstreetmap.org/search?";
		$apiEndpoint .= http_build_query([
			"format" => "jsonv2",
			"q" => $location,
			"namedetails" => 1,
			"addressdetails" => 1,
			"extratags" => 1,
			"limit" => 1,
		]);
		$httpResult = $this->http
			->get($apiEndpoint)
			->withTimeout(10)
			->withHeader('User-Agent', 'Budabot-Nady')
			->withHeader('accept-language', 'en')
			->waitAndReturnResponse();
		$data = @json_decode($httpResult->body);
		if ($data === false || !is_array($data) || !count($data)) {
			return null;
		}
		return $this->castClass($data[0], Nominatim::class);
	}

	/**
	 * Lookup the weather for a location
	 *
	 * @param string $lat Latitude
	 * @param string $lon Longitude
	 * @return \Budabot\Modules\WEATHER_MODULE\Weather|null
	 */
	public function lookupWeatherForCoords($lat, $lon) {
		$apiEndpoint = "https://api.met.no/weatherapi/locationforecast/2.0/compact?";
		$apiEndpoint .= http_build_query([
			"lat" => sprintf("%.4f", $lat),
			"lon" => sprintf("%.4f", $lon),
		]);
		$httpResult = $this->http
			->get($apiEndpoint)
			->withTimeout(10)
			->withHeader('User-Agent', 'Budabot-Nady')
			->withHeader('accept-language', 'en')
			->waitAndReturnResponse();
		$data = @json_decode($httpResult->body);
		if ($data === null || $data === false || !is_object($data)) {
			return null;
		}
		return $this->castClass($data, Weather::class);
	}

	/**
	 * Try to convert a wind degree into a wind direction
	 */
	public function degreeToDirection($degree) {
		$mapping = [
			  0 => "N",
			 22 => "NNE",
			 45 => "NE",
			 67 => "ENE",
			 90 => "E",
			112 => "ESE",
			135 => "SE",
			157 => "SSE",
			180 => "S",
			202 => "SSW",
			225 => "SW",
			247 => "WSW",
			270 => "W",
			292 => "WNW",
			315 => "NW",
			337 => "NNW",
			360 => "N",
		];
		$current = "unknown";
		$currentDiff = 360;
		foreach ($mapping as $mapDeg => $mapDir) {
			if (abs($degree-$mapDeg) < $currentDiff) {
				$current = $mapDir;
				$currentDiff = abs($degree-$mapDeg);
			}
		}
		return $current;
	}

	/**
	 * Convert the windspeed in m/s into the wind's strength according to beaufort
	 */
	public function getWindStrength($speed) {
		$beaufortScale = [
			32.7 => 'hurricane',
			28.5 => 'violent storm',
			24.5 => 'storm',
			20.8 => 'strong gale',
			17.2 => 'gale',
			13.9 => 'high wind',
			10.8 => 'strong breeze',
			 8.0 => 'fresh breeze',
			 5.5 => 'moderate breeze',
			 3.4 => 'gentle breeze',
			 1.6 => 'light breeze',
			 0.5 => 'light air',
			 0.0 => 'calm',
		];
		foreach ($beaufortScale as $windSpeed => $windStrength) {
			if ($speed >= $windSpeed) {
				return $windStrength;
			}
		}
		return 'unknown';
	}

	/**
	 * Return a link to OpenStreetMap at the given coordinates
	 *
	 * @param \Budabot\Modules\WEATHER_MODULE\Nominatim $nominatim The location object
	 * @return string The URL to OSM
	 */
	public function getOSMLink(Nominatim $nominatim) {
		$zoom = 12; // Zoom is 1 to 20 (full in)
		$lat = number_format($nominatim->lat, 4);
		$lon = number_format($nominatim->lon, 4);

		return "https://www.openstreetmap.org/#map=$zoom/$lat/$lon";
	}

	/**
	 * Convert the written temperature unit to short
	 *
	 * @param string $name Name of the unit ("celsius", "fahrenheit")
	 * @return string
	 */
	protected function nameToDegree($name) {
		if ($name === 'fahrenheit') {
			return "°F";
		}
		return "°C";
	}

	/**
	 * Convert a forecast icon (e.g. "heavysnowshowersandthunder") into a sentence
	 *
	 * @param string $icon The icon name
	 * @return string A forecast summary
	 */
	public function iconToForecastSummary($icon) {
		$icon = preg_replace("/_.+/", "", $icon);
		preg_match_all(
			"/(and|clear|cloudy|fair|fog|heavy|light|partly|rain|showers|sky|sleet|snow|thunder)/",
			$icon,
			$matches
		);
		return implode(" ", $matches[1]);
	}

	public function renderWeather(Nominatim $nominatim, Weather $weather) {
		$units = $weather->properties->meta->units;
		$currentWeather = $weather->properties->timeseries[0]->data->instant->details;
		$currentIcon = $weather->properties->timeseries[0]->data->next_1_hours->summary->symbol_code;
		$forecastIcon = $weather->properties->timeseries[0]->data->next_6_hours->summary->symbol_code;
		$currentSummary = $this->iconToForecastSummary($currentIcon);
		$forecastSummary = $this->iconToForecastSummary($forecastIcon);
		$precipitation = $weather->properties->timeseries[0]->data->next_1_hours->details->precipitation_amount;
		$precipitationForecast = $weather->properties->timeseries[0]->data->next_6_hours->details->precipitation_amount;
		$mapCommand = $this->text->makeChatcmd("OpenStreetMap", "/start ".$this->getOSMLink($nominatim));
		$lastUpdated = $weather->properties->timeseries[0]->time;
		$lastUpdated = str_replace("T", " ", $lastUpdated);
		$lastUpdated = str_replace("Z", " UTC", $lastUpdated);
		$tempDegree = $this->nameToDegree($units->air_temperature);
		$windDirection = $this->degreeToDirection($currentWeather->wind_from_direction);
		$windStrength = $this->getWindStrength($currentWeather->wind_speed);
		$osmLicence = preg_replace_callback(
			"/(http[^ ]+)/",
			function($matched) {
				return $this->text->makeChatcmd($matched[1], "/start ".$matched[1]);
			},
			lcfirst($nominatim->licence)
		);
		$blob = "Last Updated: <highlight>$lastUpdated<end><br>" .
			"<br>" .
			"Location: <highlight>{$nominatim->display_name}<end><br>";
		if ($nominatim->extratags->population) {
			$blob .= "Population: <highlight>".
				number_format($nominatim->extratags->population).
				"<end><br>";
		}
		$blob .= "Lat/Lon: <highlight>{$nominatim->lat}° {$nominatim->lon}°<end> {$mapCommand}<br>" .
			"Height: <highlight>{$weather->geometry->coordinates[2]}m<end><br>" .
			"<br>" .
			"Currently: <highlight>{$currentWeather->air_temperature}{$tempDegree}<end>, <highlight>$currentSummary<end>";
		if ($precipitation > 0) {
			$blob .= " (<highlight>{$precipitation}{$units->precipitation_amount}<end> precipitation)";
		}
		$blob .= "<br>";
		$blob .= "Forecast: <highlight>{$forecastSummary}<end>";
		if ($precipitationForecast > 0) {
			$blob .= " (<highlight>{$precipitationForecast}{$units->precipitation_amount}<end> precipitation)";
		}
		$blob .= "<br>";
		$blob .= "Clouds: <highlight>{$currentWeather->cloud_area_fraction} {$units->cloud_area_fraction}<end><br>" .
			"Pressure: <highlight>{$currentWeather->air_pressure_at_sea_level} {$units->air_pressure_at_sea_level}<end><br>" .
			"Humidity: <highlight>{$currentWeather->relative_humidity} {$units->relative_humidity}<end><br>" .
			"Wind: <highlight>{$windStrength}<end> (<highlight>{$currentWeather->wind_speed} {$units->wind_speed}<end>) from <highlight>$windDirection<end><br>" .
			"<br><br>".
			"Location search " . $osmLicence . "<br>".
			"Weather forecast data by Meteorologisk institutt of Norway";
		return $blob;
	}
}

class Nominatim {
	/** @var string */
	public $lat;
	/** @var string */
	public $lon;
	/** @var string */
	public $display_name;
	/** @var string[] */
	public $boundingbox;
	/** @var int */
	public $place_id;
	/** @var string */
	public $licence;
	/** @var string */
	public $osm_type;
	/** @var int */
	public $osm_id;
	/** @var \stdClass */
	public $namedetails;
	/** @var string */
	public $type;
	/** @var string */
	public $category;
	/** @var \Budabot\Modules\WEATHER_MODULE\NominatimAddress */
	public $address;
	/** @var \stdClass */
	public $extratags;
}

class NominatimAddress {
	/** @var string */
	public $suburb;
	/** @var string */
	public $town;
	/** @var string */
	public $county;
	/** @var string */
	public $state;
	/** @var string */
	public $postcode;
	/** @var string */
	public $country;
	/** @var string */
	public $country_code;
}

class Weather {
	/** @var string */
	public $type;
	/** @var \Budabot\Modules\WEATHER_MODULE\Geometry */
	public $geometry;
	/** @var \Budabot\Modules\WEATHER_MODULE\Properties */
	public $properties;
}

class Properties {
	/** @var \Budabot\Modules\WEATHER_MODULE\Meta */
	public $meta;
	/** @var \Budabot\Modules\WEATHER_MODULE\Timeseries[] */
	public $timeseries;
}

class Meta {
	/** @var string */
	public $updated_at;
	/** @var \Budabot\Modules\WEATHER_MODULE\Units */
	public $units;
}

class Units {
	/** @var string */
	public $air_pressure_at_sea_level;
	/** @var string */
	public $air_temperature;
	/** @var string */
	public $cloud_area_fraction;
	/** @var string */
	public $precipitation_amount;
	/** @var string */
	public $relative_humidity;
	/** @var string */
	public $wind_from_direction;
	/** @var string */
	public $wind_speed;
}

class Timeseries {
	/** @var string */
	public $time;
	/** @var \Budabot\Modules\WEATHER_MODULE\WeatherData */
	public $data;
}

class WeatherData {
	/** @var \Budabot\Modules\WEATHER_MODULE\Instant */
	public $instant;
}

class Instant {
	/** @var \Budabot\Modules\WEATHER_MODULE\InstantDetails */
	public $details;
}

class InstantDetails {
	/** @var float */
	public $air_pressure_at_sea_level;
	/** @var float */
	public $air_temperature;
	/** @var float */
	public $cloud_area_fraction;
	/** @var float */
	public $dew_point_temperature;
	/** @var float */
	public $relative_humidity;
	/** @var float */
	public $wind_from_direction;
	/** @var float */
	public $wind_speed;
}

class Geometry {
	/** @var string */
	public $type;
	/** @var array<float,float,int> */
	public $coordinates;
}
