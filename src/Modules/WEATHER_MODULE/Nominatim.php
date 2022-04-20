<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use Nadybot\Core\JSONDataModel;
use stdClass;

class Nominatim extends JSONDataModel {
	public string $lat;
	public string $lon;
	public string $display_name;
	/** @var string[] */
	public array $boundingbox;
	public int $place_id;
	public string $licence;
	public string $osm_type;
	public int $osm_id;
	public stdClass $namedetails;
	public string $type;
	public string $category;
	public NominatimAddress $address;
	public stdClass $extratags;
}
