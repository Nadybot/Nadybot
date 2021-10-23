<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PCharacter extends Base {
	protected static string $regExp = "[a-zA-Z][a-zA-Z0-9-]{3,11}";
	protected string $value;

	public function __construct(string $value) {
		$this->value = ucfirst(strtolower($value));
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
