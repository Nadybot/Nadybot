<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

enum HopColorType: string implements EnumParameterInterface, EnumExampleInterface {
	public static function getParamRegexp(): string {
		return 'tag|text';
	}

	public static function fromParam(string $param): self {
		return self::from($param);
	}

	public static function getExample(): string {
		return 'tag|text';
	}

	case TagColor = 'tag';
	case TextColor = 'text';
}
