<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use function Safe\preg_match;

abstract class Base {
	protected static string $preRegExp = '';
	protected static string $regExp = '';

	abstract public function __construct(string $value);

	abstract public function __toString(): string;

	abstract public function __invoke(): mixed;

	public static function getRegexp(): string {
		return static::$regExp;
	}

	public static function getPreRegexp(): string {
		return static::$preRegExp;
	}

	public static function matches(string $string): bool {
		return preg_match(chr(1) . '^(?' . static::$preRegExp . ')(' . static::$regExp . ')$' . chr(1) . 'is', $string) > 0;
	}

	public static function getExample(): ?string {
		return null;
	}
}
