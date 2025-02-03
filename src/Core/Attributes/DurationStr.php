<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class DurationStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return "(?:(?:,?\s*\d+(?:yr?|years?|m|months?|w|weeks?|d|days?|h|hrs?|hours?|m|mins?|s|secs?))+|[1-9]\d*)";
	}
}
