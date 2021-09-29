<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

abstract class Base {
	protected static string $regExp = "";

	abstract public function __construct(string $value);

	abstract public function __toString(): string;

	abstract public function __invoke();

	public static function getRegexp(): string {
		return static::$regExp;
	}
}
