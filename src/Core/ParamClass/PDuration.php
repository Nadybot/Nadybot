<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use Nadybot\Core\Util;

/**
 * This is a valid budatime duration string, returned as given,
 * or as seconds with `->toSecs()`
 */
class PDuration extends Base {
	protected static string $strictRegExp = "(?:(?:,?\s*\d+(?:yr?|years?|m|months?|w|weeks?|d|days?|h|hrs?|hours?|m|mins?|s|secs?))+)";
	protected static string $regExp = "(?:(?:,?\s*\d+(?:yr?|years?|m|months?|w|weeks?|d|days?|h|hrs?|hours?|m|mins?|s|secs?))+|[1-9]\d*)";
	protected string $value;

	public function __construct(string $value) {
		$this->value = $value;
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}

	/** Return the duration in seconds */
	public function toSecs(): int {
		if (is_numeric($this->value)) {
			return (int)$this->value;
		}
		return Util::parseTime($this->value);
	}

	/** Get a more strict regular expression to match a valid budatime string */
	public static function getStrictRegexp(): string {
		return static::$strictRegExp;
	}
}
