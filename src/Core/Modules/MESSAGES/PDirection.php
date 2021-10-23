<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Nadybot\Core\ParamClass\Base;

class PDirection extends Base {
	protected static string $regExp = "to|->|-&gt;|<->|&lt;-&gt;";
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

	public function isTwoWay(): bool {
		return in_array($this->value, ["<->", "&lt;-&gt;"]);
	}
}
