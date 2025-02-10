<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ValueError;

enum Ability: string implements EnumParameterInterface {
	public static function tryFromShort(string $short): ?self {
		return match (strtolower(substr($short, 0, 3))) {
			'agi','agl' => static::Agility,
			'int' => static::Intelligence,
			'psy' => static::Psychic,
			'sta','stm' => static::Stamina,
			'str' => static::Strength,
			'sen','sns' => static::Sense,
			default => null,
		};
	}

	/**
	 * Create the instance based on the short name
	 *
	 * @param string $short The short name
	 *
	 * @throws ValueError if nothing matches
	 */
	public static function fromShort(string $short): self {
		$long = self::tryFromShort($short);
		if (!isset($long)) {
			throw new ValueError("\"{$short}\" is not a valid backing value for enum " . static::class);
		}
		return $long;
	}

	public static function fromParam(string $param): self {
		return self::fromShort($param);
	}

	public static function getParamRegexp(): string {
		return '(agi|agl|int|psy|sta|stm|str|sen|sns)\w*';
	}

	public function getID(): int {
		return match ($this) {
			self::Strength => 16,
			self::Agility => 17,
			self::Stamina => 18,
			self::Intelligence => 19,
			self::Sense => 20,
			self::Psychic => 21,
		};
	}

	public static function fromID(int $id): self {
		return match ($id) {
			16 => self::Strength,
			17 => self::Agility,
			18 => self::Stamina,
			19 => self::Intelligence,
			20 => self::Sense,
			21 => self::Psychic,
			default => throw new ValueError("Unknown ability {$id}"),
		};
	}

	case Agility = 'agi';
	case Intelligence = 'int';
	case Psychic = 'psy';
	case Stamina = 'sta';
	case Strength = 'str';
	case Sense = 'sen';
}
