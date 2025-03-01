<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents a title level */
enum TitleLevel: int implements EnumParameterInterface {
	/** {@inheritDoc} */
	public static function getParamRegexp(): string {
		return '[1-7]';
	}

	/** {@inheritDoc} */
	public static function fromParam(string $param): self {
		return self::from((int)$param);
	}

	/** Get the title level from the player's level */
	public static function fromLevel(int $level): self {
		return match (true) {
			$level < 15 => self::One,
			$level < 50 => self::Two,
			$level < 100 => self::Three,
			$level < 150 => self::Four,
			$level < 190 => self::Five,
			$level < 205 => self::Six,
			default => self::Seven,
		};
	}

	/** Get the level range from the player's title level */
	public function toLevelRange(): MinMax {
		return match ($this) {
			self::One => new MinMax(min: 1, max: 14),
			self::Two => new MinMax(min: 15, max: 49),
			self::Three => new MinMax(min: 50, max: 99),
			self::Four => new MinMax(min: 100, max: 149),
			self::Five => new MinMax(min: 150, max: 189),
			self::Six => new MinMax(min: 190, max: 204),
			self::Seven => new MinMax(min: 205, max: 220),
		};
	}

	case One = 1;
	case Two = 2;
	case Three = 3;
	case Four = 4;
	case Five = 5;
	case Six = 6;
	case Seven = 7;
}
