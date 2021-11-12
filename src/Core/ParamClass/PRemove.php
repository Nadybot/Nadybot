<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PRemove extends Base {
	protected static string $regExp = "remove|delete|erase|rem|del|rm|clear|off";
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
