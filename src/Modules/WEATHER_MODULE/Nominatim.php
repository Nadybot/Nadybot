<?php declare(strict_types=1);

namespace Nadybot\Modules\WEATHER_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\StringableTrait;

class Nominatim {
	use StringableTrait;

	/**
	 * @param string[]            $boundingbox
	 * @param array<string,mixed> $namedetails
	 * @param array<string,mixed> $extratags
	 *
	 * @psalm-param list<string>  $boundingbox
	 */
	public function __construct(
		public float|string $lat,
		public float|string $lon,
		public string $display_name,
		#[CastListToType('string')] public array $boundingbox,
		public int $place_id,
		public string $licence,
		public string $osm_type,
		public int $osm_id,
		public array $namedetails,
		public string $type,
		public string $category,
		public NominatimAddress $address,
		public array $extratags=[],
	) {
	}
}
