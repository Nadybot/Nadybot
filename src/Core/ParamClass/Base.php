<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use function Safe\preg_match;

/**
 * This is the abstract base class for all classes that can be used as a
 * class for parameters to a command.
 */
abstract class Base {
	protected static string $preRegExp = '';
	protected static string $regExp = '';

	abstract public function __construct(string $value);

	abstract public function __toString(): string;

	/** Get the actually wanted representation of the given command parameter */
	abstract public function __invoke(): mixed;

	/** Get the regular expression that this parameter must match */
	public static function getRegexp(): string {
		return static::$regExp;
	}

	/**
	 * Get the regular expression that must match before `getRegexp()`,
	 * but should not be included in the actual value of this parameter.
	 */
	public static function getPreRegexp(): string {
		return static::$preRegExp;
	}

	/** Check if a given string would match this class */
	public static function matches(string $string): bool {
		return preg_match(chr(1) . '^(?' . static::$preRegExp . ')(' . static::$regExp . ')$' . chr(1) . 'is', $string) > 0;
	}

	/**
	 * Get an example value for this class, or return `null` for
	 * the default, which is the parameter's name in <>
	 */
	public static function getExample(): ?string {
		return null;
	}
}
