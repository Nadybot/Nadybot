<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

class PWhere extends PSource {
	protected static string $preRegExp = "(?:->|-&gt;)\s*";
	protected static string $regExp = "[a-zA-Z*]+(?:\(.*?\))?";
	protected string $value;

	public function __construct(string $value) {
		$this->value = strtolower($value);
	}
}
