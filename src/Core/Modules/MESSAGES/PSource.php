<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Nadybot\Core\ParamClass\Base;

class PSource extends Base {
	protected static string $regExp = "[a-zA-Z-*]+(?:\(.*?\))?";
	protected string $value;

	public function __construct(string $value) {
		$this->value = strtolower($value);
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
