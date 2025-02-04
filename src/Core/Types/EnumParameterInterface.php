<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

interface EnumParameterInterface {
	public static function getParamRegexp(): string;

	public static function fromParam(string $param): self;
}
