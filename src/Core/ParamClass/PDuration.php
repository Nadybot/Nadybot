<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use Nadybot\Core\Registry;
use Nadybot\Core\Util;

class PDuration extends Base {
	protected static string $regExp = "(?:(?:,?\s*\d+(?:yr?|years?|m|months?|w|weeks?|d|days?|h|hrs?|hours?|m|mins?|s|secs?))+|[1-9]\d*)";
	protected string $value;

	public function __construct(string $value) {
		$this->value = $value;
	}

	public function toSecs(): int {
		if (is_numeric($this->value)) {
			return (int)$this->value;
		}
		$util = Registry::getInstance('util');
		if (isset($util) && $util instanceof Util) {
			return $util->parseTime($this->value);
		}
		return 0;
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
