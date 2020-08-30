<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use JsonException;
use Nadybot\Core\{
	CommandReply,
	Http,
	HttpResponse,
	Text,
};

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
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Http $http;

	/**
	 * @HandlesCommand("weather")
	 * @Matches("/^weather (.+)$/i")
	 */
	public function weatherCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$location = $args[1];
		$this->lookupLocation($location, [$this, "getWeatherForLocationResponse"], $sendto);
	}

	/**
	 * Lookup the coordinates of a location
	 */
	public function lookupLocation(string $location, callable $callback, CommandReply $sendto): void {
		$apiEndpoint = "https://nominatim.openstreetmap.org/search?";
		$apiEndpoint .= http_build_query([
			"format" => "jsonv2",
			"q" => $location,
			"namedetails" => 1,
			"addressdetails" => 1,
			"extratags" => 1,
			"limit" => 1,
		]);
		$this->http
			->get($apiEndpoint)
			->withTimeout(10)
			->withHeader('User-Agent', 'Nadybot')
			->withHeader('accept-language', 'en')
			->withCallback($callback, $sendto);
	}

	public function getWeatherForLocationResponse(HttpResponse $response, CommandReply $sendto): void {
		if ($response === null) {
			$sendto->reply("No answer from Location provider. Please try again later.");
			return;
		}
		if ($response->headers["status-code"] !== "200") {
			$sendto->reply("Error received from Location provider.");
			return;
		}
		try {
			$data = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$sendto->reply(
				"Invalid JSON received from Location provider: ".
				"<highlight>{$response->body}<end>."
			);
			return;
		}
		if (!is_array($data)) {
			$sendto->reply(
				"Invalid answer received from Location provider: ".
				"<highlight>" . print_r($data, true) . "<end>."
			);
			return;
		}
		if (!count($data)) {
			$sendto->reply("Location not found");
			return;
		}
		$nominatim = new Nominatim();
		$nominatim->fromJSON($data[0]);
		$this->lookupWeather($nominatim, $sendto);
	}

	/**
	 * Lookup the weather for a location
	 */
	public function lookupWeather(Nominatim $nom, CommandReply $sendto): void {
		$apiEndpoint = "https://api.met.no/weatherapi/locationforecast/2.0/compact?";
		$apiEndpoint .= http_build_query([
			"lat" => sprintf("%.4f", $nom->lat),
			"lon" => sprintf("%.4f", $nom->lon),
		]);
		$this->http
			->get($apiEndpoint)
			->withTimeout(10)
			->withHeader('User-Agent', 'Nadybot')
			->withHeader('accept-language', 'en')
			->withCallback([$this, "processWeatherResult"], $nom, $sendto);
	}

	public function processWeatherResult(HttpResponse $response, Nominatim $nominatim, CommandReply $sendto): void {
		if ($response === null) {
			$sendto->reply("No answer from Weather provider. Please try again later.");
			return;
		}
		if ($response->headers["status-code"] !== "200") {
			$sendto->reply("Error received from Weather provider.");
			return;
		}
		try {
			$data = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$sendto->reply(
				"Invalid JSON received from Weather provider: ".
				"<highlight>{$response->body}<end>."
			);
			return;
		}
		if (!is_object($data)) {
			$sendto->reply(
				"Invalid answer received from Weather provider: ".
				"<highlight>" . print_r($data, true) . "<end>."
			);
			return;
		}
		$weather = new Weather();
		$weather->fromJSON($data);
		$this->showWeater($nominatim, $weather, $sendto);
	}

	protected function showWeater(Nominatim $nominatim, Weather $weather, CommandReply $sendto): void {
		$blob = $this->renderWeather($nominatim, $weather);
		$placeParts = explode(", ", $nominatim->display_name);
		$locationName = $placeParts[0];
		// If we're being shown just a ZIP code or house number, add one more layer of info
		if (preg_match("/^\d+/", $locationName)) {
			$locationName = "{$placeParts[1]} {$locationName}";
		}
		$header = "The current weather for <highlight>{$locationName}<end>";
		if (count($placeParts) > 2 && $nominatim->address->country_code === "us") {
			$header .= ", " . $nominatim->address->state;
		} elseif (count($placeParts) > 1) {
			$header .= ", " . $nominatim->address->country;
		}
		$currentIcon = $weather->properties->timeseries[0]->data->next_1_hours->summary->symbol_code;
		$currentSummary = $this->iconToForecastSummary($currentIcon);
		$currentTemp = $weather->properties->timeseries[0]->data->instant->details->air_temperature;
		$tempUnit = $this->nameToDegree($weather->properties->meta->units->air_temperature);
		$blob = $this->text->makeBlob("details", $blob, strip_tags($header));

		$msg = "$header: <highlight>{$currentTemp}{$tempUnit}<end>, ".
			"<highlight>{$currentSummary}<end> [{$blob}]";
		$sendto->reply($msg);
	}

	/**
	 * Try to convert a wind degree into a wind direction
	 */
	public function degreeToDirection(float $degree): string {
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
	public function getWindStrength(float $speed): string {
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
	 * @param \Nadybot\Modules\WEATHER_MODULE\Nominatim $nominatim The location object
	 * @return string The URL to OSM
	 */
	public function getOSMLink(Nominatim $nominatim): string {
		$zoom = 12; // Zoom is 1 to 20 (full in)
		$lat = number_format((float)$nominatim->lat, 4);
		$lon = number_format((float)$nominatim->lon, 4);

		return "https://www.openstreetmap.org/#map=$zoom/$lat/$lon";
	}

	/**
	 * Convert the written temperature unit to short
	 *
	 * @param string $name Name of the unit ("celsius", "fahrenheit")
	 * @return string
	 */
	protected function nameToDegree(string $name): string {
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
	public function iconToForecastSummary(string $icon): string {
		$icon = preg_replace("/_.+/", "", $icon);
		preg_match_all(
			"/(and|clear|cloudy|fair|fog|heavy|light|partly|rain|showers|sky|sleet|snow|thunder)/",
			$icon,
			$matches
		);
		return implode(" ", $matches[1]);
	}

	public function renderWeather(Nominatim $nominatim, Weather $weather): string {
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
				number_format((float)$nominatim->extratags->population).
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
		$blob .= "Clouds: <highlight>{$currentWeather->cloud_area_fraction}{$units->cloud_area_fraction}<end><br>" .
			"Pressure: <highlight>{$currentWeather->air_pressure_at_sea_level} {$units->air_pressure_at_sea_level}<end><br>" .
			"Humidity: <highlight>{$currentWeather->relative_humidity}{$units->relative_humidity}<end><br>" .
			"Wind: <highlight>{$windStrength}<end> (<highlight>{$currentWeather->wind_speed} {$units->wind_speed}<end>) from <highlight>$windDirection<end><br>" .
			"<br><br>".
			"Location search " . $osmLicence . "<br>".
			"Weather forecast data by Meteorologisk institutt of Norway";
		return $blob;
	}
}
