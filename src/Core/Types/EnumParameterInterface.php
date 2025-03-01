<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This allows enums to be used as a command parameter */
interface EnumParameterInterface {
	/** Get the regular expression this Enum has to match */
	public static function getParamRegexp(): string;

	/** Create the enum instance based on a command parameter */
	public static function fromParam(string $param): self;
}
