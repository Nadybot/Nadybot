<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents which type of color of a hop we're referring to: text or tag */
enum HopColorType: string implements EnumParameterInterface, EnumExampleInterface {
	/** {@inheritDoc} */
	public static function getParamRegexp(): string {
		return 'tag|text';
	}

	/** {@inheritDoc} */
	public static function fromParam(string $param): self {
		return self::from($param);
	}

	/** {@inheritDoc} */
	public static function getExample(): string {
		return 'tag|text';
	}

	case TagColor = 'tag';
	case TextColor = 'text';
}
