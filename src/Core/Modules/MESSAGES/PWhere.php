<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

class PWhere extends PSource {
	protected static string $regExp = "(?:->|-&gt;)\s*[a-zA-Z*]+(?:\(.*?\))?";
	protected string $value;

	public function __construct(string $value) {
		$value = preg_replace("/^(->|-&gt;)\s*/is", "", $value);
		$this->value = strtolower($value);
	}
}
