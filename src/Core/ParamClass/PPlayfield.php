<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PPlayfield extends Base {
	protected static string $regExp = "[0-9A-Za-z]+[A-Za-z]";
	protected string $value;

	public function __construct(string $value) {
		$this->value = strtoupper($value);
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}

	public static function getExample(): ?string {
		return "&lt;playfield&gt;";
	}
}
