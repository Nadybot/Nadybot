<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

class PVia extends PSource {
	protected static string $regExp = "(via)\s+[a-zA-Z*]+(?:\(.*?\))?";
	protected string $value;

	public function __construct(string $value) {
		$value = preg_replace("/^(via)\s+/is", "", $value);
		$this->value = strtolower($value);
	}
}
