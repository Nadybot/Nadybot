<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Attributes;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster};
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Attribute(Attribute::TARGET_PARAMETER)]

class CastToTiming implements PropertyCaster {
	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		switch ($value) {
			case 'StaticEurope':
				return SiteUpdate::TIMING_EU;
			case 'StaticUS':
				return SiteUpdate::TIMING_US;
			default:
				return SiteUpdate::TIMING_DYNAMIC;
		}
	}
}
