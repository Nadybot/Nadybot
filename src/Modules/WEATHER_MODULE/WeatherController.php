<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use function Safe\{json_decode, preg_match};
use Amp\Cache\LocalCache;
use Amp\Http\Client\Interceptor\AddRequestHeader;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Safe,
	Text,
	UserException,
};
use Safe\Exceptions\JsonException;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "weather",
		accessLevel: "guest",
		description: "View Weather",
	)
]
class WeatherController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	/** @var LocalCache<string> */
	private LocalCache $cache;

	public function __construct() {
		$this->cache = new LocalCache(60000, 100);
	}

	/**
	 * Lookup the weather for a given location
	 *
	 * If the location found is not the right one, try adding country codes or other
	 * information like state, region, or zip, separated by a comma.
	 * You can search for anything in any language, down to house numbers, streets
	 * and objects, but also for countries, states and so on.
	 */
	#[NCA\HandlesCommand("weather")]
	#[NCA\Help\Example("<symbol>weather uk")]
	#[NCA\Help\Example("<symbol>weather london")]
	#[NCA\Help\Example("<symbol>weather westminster")]
	#[NCA\Help\Example("<symbol>weather hannover,us")]
	#[NCA\Help\Example("<symbol>weather cologne cathedral")]
	#[NCA\Help\Example("<symbol>weather athens,ga")]
	public function weatherCommand(CmdContext $context, string $location): void {
		try {
			$nominatim = $this->lookupLocation($location);
			$weather = $this->lookupWeather($nominatim);
			$msg = $this->getWeatherBlob($nominatim, $weather);
		} catch (UserException $e) {
			$msg = $e->getMessage();
		}
		$context->reply($msg);
	}

	/**
	 * Lookup the coordinates of a location
	 *
	 * @psalm-param callable(HttpResponse, CmdContext, mixed...) $callback
	 */
	public function lookupLocation(string $location): Nominatim {
		$apiEndpoint = "https://nominatim.openstreetmap.org/search?";
		$apiEndpoint .= http_build_query([
			"format" => "jsonv2",
			"q" => $location,
			"namedetails" => 1,
			"addressdetails" => 1,
			"extratags" => 1,
			"limit" => 1,
		]);
		$body = $this->cache->get($apiEndpoint);
		if (!isset($body)) {
			$client = $this->builder
				->intercept(new AddRequestHeader('accept-language', 'en'))
				->build();

			$response = $client->request(new Request($apiEndpoint));
			if ($response->getStatus() !== 200) {
				throw new UserException("Error received from Location provider.");
			}
			$body = $response->getBody()->buffer();
			if ($body === '') {
				throw new UserException("No answer from Location provider. Please try again later.");
			}
			$this->cache->set($apiEndpoint, $body, 24*3600);
		}
		return $this->decodeNominatim($body);
	}

	/** Lookup the weather for a location */
	public function lookupWeather(Nominatim $nom): Weather {
		$apiEndpoint = "https://api.met.no/weatherapi/locationforecast/2.0/compact?";
		$apiEndpoint .= http_build_query([
			"lat" => sprintf("%.4f", $nom->lat),
			"lon" => sprintf("%.4f", $nom->lon),
		]);
		$client = $this->builder
			->intercept(new AddRequestHeader('accept-language', 'en'))
			->build();

		$response = $client->request(new Request($apiEndpoint));
		if ($response->getStatus() !== 200) {
			throw new UserException("Error received from Weather provider.");
		}
		$body = $response->getBody()->buffer();
		if ($body === '') {
			throw new UserException("No answer from Weather provider. Please try again later.");
		}
		return $this->decodeWeather($body);
	}

	/** Try to convert a wind degree into a wind direction */
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

	/** Convert the windspeed in m/s into the wind's strength according to beaufort */
	public function getWindStrength(float $speed): string {
		$beaufortScale = [
			'32.7' => 'hurricane',
			'28.5' => 'violent storm',
			'24.5' => 'storm',
			'20.8' => 'strong gale',
			'17.2' => 'gale',
			'13.9' => 'high wind',
			'10.8' => 'strong breeze',
			 '8.0' => 'fresh breeze',
			 '5.5' => 'moderate breeze',
			 '3.4' => 'gentle breeze',
			 '1.6' => 'light breeze',
			 '0.5' => 'light air',
			 '0.0' => 'calm',
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
	 *
	 * @return string The URL to OSM
	 */
	public function getOSMLink(Nominatim $nominatim): string {
		$zoom = 12; // Zoom is 1 to 20 (full in)
		$lat = number_format((float)$nominatim->lat, 4);
		$lon = number_format((float)$nominatim->lon, 4);

		return "https://www.openstreetmap.org/#map={$zoom}/{$lat}/{$lon}";
	}

	/**
	 * Convert a forecast icon (e.g. "heavysnowshowersandthunder") into a sentence
	 *
	 * @param string $icon The icon name
	 *
	 * @return string A forecast summary
	 */
	public function iconToForecastSummary(string $icon): string {
		$icon = Safe::pregReplace("/_.+/", "", $icon);
		$matches = Safe::pregMatchAll(
			"/(and|clear|cloudy|fair|fog|heavy|light|partly|rain|showers|sky|sleet|snow|thunder)/",
			$icon,
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
			function (array $matched) {
				return $this->text->makeChatcmd($matched[1], "/start ".$matched[1]);
			},
			lcfirst($nominatim->licence)
		);
		$blob = "Last Updated: <highlight>{$lastUpdated}<end><br>" .
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
			"Currently: <highlight>{$currentWeather->air_temperature}{$tempDegree}<end>, <highlight>{$currentSummary}<end>";
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
			"Wind: <highlight>{$windStrength}<end> (<highlight>{$currentWeather->wind_speed} {$units->wind_speed}<end>) from <highlight>{$windDirection}<end><br>" .
			"<br><br>".
			"Location search " . $osmLicence . "<br>".
			"Weather forecast data by Meteorologisk institutt of Norway";
		return $blob;
	}

	/** @return string|string[] */
	protected function getWeatherBlob(Nominatim $nominatim, Weather $weather): string|array {
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

		$msg = $this->text->blobWrap(
			"{$header}: <highlight>{$currentTemp}{$tempUnit}<end>, ".
			"<highlight>{$currentSummary}<end> [",
			$blob,
			"]"
		);
		return $msg;
	}

	/**
	 * Convert the written temperature unit to short
	 *
	 * @param string $name Name of the unit ("celsius", "fahrenheit")
	 */
	protected function nameToDegree(string $name): string {
		if ($name === 'fahrenheit') {
			return "°F";
		}
		return "°C";
	}

	private function decodeNominatim(string $body): Nominatim {
		try {
			$data = json_decode($body);
		} catch (JsonException $e) {
			throw new UserException(
				"Invalid JSON received from Location provider: ".
				"<highlight>{$body}<end>."
			);
		}
		if (!is_array($data)) {
			throw new UserException(
				"Invalid answer received from Location provider: ".
				"<highlight>" . print_r($data, true) . "<end>."
			);
		}
		if (!count($data)) {
			throw new UserException("Location not found");
		}
		$nominatim = new Nominatim();
		$nominatim->fromJSON($data[0]);
		return $nominatim;
	}

	private function decodeWeather(string $body): Weather {
		try {
			$data = json_decode($body);
		} catch (JsonException $e) {
			throw new UserException(
				"Invalid JSON received from Weather provider: ".
				"<highlight>{$body}<end>."
			);
		}
		if (!is_object($data)) {
			throw new UserException(
				"Invalid answer received from Weather provider: ".
				"<highlight>" . print_r($data, true) . "<end>."
			);
		}
		$weather = new Weather();
		$weather->fromJSON($data);
		return $weather;
	}
}
