<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ValueError;

enum Ability: string {
	public static function tryFromShort(string $short): ?static {
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
	public static function fromShort(string $short): static {
		$long = static::tryFromShort($short);
		if (!isset($long)) {
			throw new ValueError("\"{$short}\" is not a valid backing value for enum " . static::class);
		}
		return $long;
	}

	public static function getNameRegexp(): string {
		return '(agi|agl|int|psy|sta|stm|str|sen|sns)\w*';
	}

	case Agility = 'agi';
	case Intelligence = 'int';
	case Psychic = 'psy';
	case Stamina = 'sta';
	case Strength = 'str';
	case Sense = 'sen';
}
