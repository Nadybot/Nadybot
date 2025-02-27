<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This string accepts any valid Budatime string */
#[Attribute(Attribute::TARGET_PARAMETER)]
class DurationStr extends AbstractParamAttribute {
	/** {@inheritDoc} */
	public function getRegexp(): string {
		return "(?:(?:,?\s*\d+(?:yr?|years?|m|months?|w|weeks?|d|days?|h|hrs?|hours?|m|mins?|s|secs?))+|[1-9]\d*)";
	}
}
