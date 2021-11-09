<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

abstract class Base {
	protected static string $preRegExp = "";
	protected static string $regExp = "";

	abstract public function __construct(string $value);

	abstract public function __toString(): string;

	/** @return mixed */
	abstract public function __invoke();

	public static function getRegexp(): string {
		return static::$regExp;
	}

	public static function getPreRegexp(): string {
		return static::$preRegExp;
	}
}
