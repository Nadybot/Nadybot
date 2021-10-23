<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use Nadybot\Core\Registry;

class PDuration extends Base {
	protected static string $regExp = "(?:,?\s*\d+(?:yr?|years?|m|months?|w|weeks?|d|days?|h|hrs?|hours?|m|mins?|s|secs?))+";
	protected string $value;

	public function __construct(string $value) {
		$this->value = $value;
	}

	public function toSecs(): int {
		return Registry::getInstance('util')->parseTime($this->value);
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
