<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PAttribute extends Base {
	protected static string $regExp = "agi(?:lity)?|agl|int(?:elligence)?|psy(?:chic)?|sen(?:se)?|str(?:ength)?|sta(?:mina)?";
	protected string $value;
	/** @var array<string,string> */
	protected static array $mapping = [
		"agi" => "agility",
		"agl" => "agility",
		"int" => "intelligence",
		"psy" => "psychic",
		"sen" => "sense",
		"str" => "strength",
		"sta" => "stamina"
	];

	public function __construct(string $value) {
		$this->value = strtolower($value);
		$this->value = static::$mapping[$this->value] ?? $this->value;
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
