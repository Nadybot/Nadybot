<?php

namespace Budabot\User\Modules;

use Budabot\Core\XML;

/**
 * Authors:
 *	- Tyrence (RK2)
 *	- Lucier (RK1) ?
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

	/** @Inject */
	public $text;
	
	/**
	 * @HandlesCommand("weather")
	 * @Matches("/^weather (.+)$/i")
	 */
	public function weatherCommand($message, $channel, $sender, $sendto, $args) {
		$location = $args[1];
		$blob = '';

		$geolookup = "http://api.wunderground.com/auto/wui/geo/GeoLookupXML/index.xml?query=".urlencode($location);
		$current   = "http://api.wunderground.com/auto/wui/geo/WXCurrentObXML/index.xml?query=".urlencode($location);
		$forecast  = "http://api.wunderground.com/auto/wui/geo/ForecastXML/index.xml?query=".urlencode($location);
		$alerts    = "http://api.wunderground.com/auto/wui/geo/AlertsXML/index.xml?query=".urlencode($location);

		$geolookup = file_get_contents($geolookup);

		// Geolookup
		if (XML::spliceData($geolookup, "<wui_error>", "</wui_error>") != "") {
			$sendto->reply("No information is available for <highlight>".$location."<end>.");
			return;
		}

		$locations = XML::spliceMultiData($geolookup, "<name>", "</name>");
		if (count($locations) > 1) {
			$blob .= "Multiple hits for $location.\n\n";
			foreach ($locations as $spot) {
				$blob .= $this->text->makeChatcmd($spot, "/tell <myname> weather $spot")."\n";
			}

			$msg = $this->text->makeBlob('Weather Locations', $blob);
			$sendto->reply($msg);
			return;
		}

		$sendto->reply("Collecting data for <highlight>".$location."<end>.");

		$current   = file_get_contents($current);
		$forecast  = file_get_contents($forecast);
		$alerts    = file_get_contents($alerts);

		// CURRENT
		$updated = XML::spliceData($current, "<observation_time_rfc822>", "</observation_time_rfc822>");

		if ($updated == ", :: GMT") {
			$sendto->reply("No information is available for <highlight>".$location."<end>.");
			return;
		}

		$credit = XML::spliceData($current, "<credit>", "</credit>");
		$crediturl = XML::spliceData($current, "<credit_URL>", "</credit_URL>");
		$observeLoc = XML::spliceData($current, "<observation_location>", "</observation_location>");
		$fullLoc = XML::spliceData($observeLoc, "<full>", "</full>");
		$country = XML::spliceData($observeLoc, "<country>", "</country>");
		$lat = XML::spliceData($observeLoc, "<latitude>", "</latitude>");
		$lon = XML::spliceData($observeLoc, "<longitude>", "</longitude>");
		$elevation = XML::spliceData($observeLoc, "<elevation>", "</elevation>");
		$weather = XML::spliceData($current, "<weather>", "</weather>");
		$tempstr = XML::spliceData($current, "<temperature_string>", "</temperature_string>");
		$humidity = XML::spliceData($current, "<relative_humidity>", "</relative_humidity>");
		$windstr = XML::spliceData($current, "<wind_string>", "</wind_string>");
		$windgust = XML::spliceData($current, "<wind_gust_mph>", "</wind_gust_mph>");
		$pressurestr = XML::spliceData($current, "<pressure_string>", "</pressure_string>");
		$dewstr = XML::spliceData($current, "<dewpoint_string>", "</dewpoint_string>");
		$heatstr = XML::spliceData($current, "<heat_index_string>", "</heat_index_string>");
		$windchillstr = XML::spliceData($current, "<windchill_string>", "</windchill_string>");
		$visibilitymi = XML::spliceData($current, "<visibility_mi>", "</visibility_mi>");
		$visibilitykm = XML::spliceData($current, "<visibility_km>", "</visibility_km>");

		$latlonstr  = number_format(abs($lat), 1);
		if (abs($lat) == $lat) {
			$latlonstr .= "N ";
		} else {
			$latlonstr .= "S ";
		}
		$latlonstr .= number_format(abs($lon), 1);
		if (abs($lon) == $lon) {
			$latlonstr .= "E ";
		} else {
			$latlonstr .= "W ";
		}
		$latlonstr .= $this->text->makeChatcmd("Google Map", "/start http://maps.google.com/maps?q=$lat,$lon")." ";
		$latlonstr .= $this->text->makeChatcmd("Wunder Map", "/start http://www.wunderground.com/wundermap/?lat=$lat&lon=$lon&zoom=10")."\n\n";
		$blob .= "Credit: <highlight>".$this->text->makeChatcmd($credit, "/start $crediturl")."<end>\n";
		$blob .= "Last Updated: <highlight>$updated<end>\n\n";
		$blob .= "Location: <highlight>$fullLoc, $country<end>\n";
		$blob .= "Lat/Lon: <highlight>$latlonstr<end>";

		$blob .= "Currently: <highlight>$tempstr, $weather<end>\n";
		$blob .= "Humidity: <highlight>$humidity<end>\n";
		$blob .= "Dew Point: <highlight>$dewstr<end>\n";
		$blob .= "Wind: <highlight>$windstr<end>";
		if ($windgust) {
			$blob .= " (Gust:$windgust mph)\n";
		} else {
			$blob .= "\n";
		}
		if ($heatstr != "NA") {
			$blob .= "Heat Index: <highlight>$heatstr<end>\n";
		}
		if ($windchillstr != "NA") {
			$blob .= "Windchill: <highlight>$windchillstr<end>\n";
		}
		$blob .= "Pressure: <highlight>$pressurestr<end>\n";
		$blob .= "Visibility: <highlight>$visibilitymi miles, $visibilitykm km<end>\n";
		$blob .= "Elevation: <highlight>$elevation<end>\n";

		// ALERTS
		$alertitems = XML::spliceMultiData($alerts, "<AlertItem>", "</AlertItem>");

		if (count($alertitems) == 0) {
			$blob .= "\n<header2>Alerts:<end> None reported.\n\n";
		} else {
			forEach ($alertitems as $thisalert) {
				$blob .= "\n<header2>Alert: ".XML::spliceData($thisalert, "<description>", "</description>")."<end>\n\n";
				// gotta find date/expire manually.
				$start = strpos($thisalert, ">", strpos($thisalert, "<date epoch="))+1;
				$end = strpos($thisalert, "<", $start);
				$blob .= "Issued:<highlight>" . substr($thisalert, $start, $end - $start) . "<end>\n";

				$start = strpos($thisalert, ">", strpos($thisalert, "<expires epoch="))+1;
				$end = strpos($thisalert, "<", $start);
				$blob .= "Expires:<highlight>" . substr($thisalert, $start, $end - $start) . "<end>\n";
				$blob .= XML::spliceData($thisalert, "<message>", "</message>")."";
			}
		}

		// FORECAST
		$simpleforecast = XML::spliceData($forecast, "<simpleforecast>", "</simpleforecast>");
		$forecastday = XML::spliceMultiData($simpleforecast, "<forecastday>", "</forecastday>");
		if (count($forecastday)>0) {
			forEach ($forecastday as $day) {
				if (!($condition = XML::spliceData($day, "<conditions>", "</conditions>"))) {
					break;
				}

				$low[0] = XML::spliceData($day, "<low>", "</low>");
				$low[1] = XML::spliceData($low[0], "<fahrenheit>", "</fahrenheit");
				$low[2] = XML::spliceData($low[0], "<celsius>", "</celsius");
				$high[0] = XML::spliceData($day, "<high>", "</high>");
				$high[1] = XML::spliceData($high[0], "<fahrenheit>", "</fahrenheit");
				$high[2] = XML::spliceData($high[0], "<celsius>", "</celsius");
				$pop = XML::spliceData($day, "<pop>", "</pop>");

				$blob .= XML::spliceData($day, "<weekday>", "</weekday>").": <highlight>$condition<end>";
				if (0 == $pop) {
					$blob .= "\n";
				} else {
					$blob .= " ($pop% Precip)\n";
				}

				$blob .= "High: <highlight>";
				$blob .= $high[1]."F";
				$blob .= $high[2]."C<end>    ";

				$blob .= "Low: <highlight>".$low[1]."F";
				$blob .= $low[2]."C<end>\n\n";
			}
		}

		$msg = $this->text->makeBlob('Weather: '.$location, $blob);

		$sendto->reply($msg);
	}
}
