<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\Safe;

enum AodbType: string {
	/** Names of nano crystals */
	public const CRYSTAL_PATTERN = '/^(?:Premium |Sealed |Weird Looking |Bugged )?Nano ?Crystal|Startup Crystal|^Alien Matrix (?:Alpha|Beta) Box$/';

	/** Names of instruction discs and their robot counterparts */
	public const DISC_PATTERN = '/^(?:(?:Robot |Broken )?Instruction Disc|(?:Robot )?Compiled Algorithm|Programmed Photon Particle Emitter) \(/';

	/** Names of tainted nano crystals that need to be repaired first */
	public const TAINTED_PATTERN = '/^(?:(?:Badly|Hacked|Blood Stained and) Corroded|Badly Eroded|Failed Repaired'.
		'|(?:Overcharged Corroded|Super Charged) Nano'.
		'|(?:Dirty Money|Severly Corroded|Tainted|Snow Crashed|Cracked and Miskept) Shadow) Crystal \('.
		'|^Corroded Crystal with Bullet Holes \(/';

	/**
	 * Get the type for the value of the ItemClass stat
	 *
	 * Crystals, discs and tainted items are no item classes in the game,
	 * they are Misc there.
	 */
	public static function fromItemClass(int $itemClass): self {
		return match ($itemClass) {
			1 => self::Weapon,
			2 => self::Armor,
			3 => self::Implant,
			5 => self::Spirit,
			default => self::Misc,
		};
	}

	/**
	 * Get the type of an item template from its ItemClass stat and its name
	 *
	 * Crystals, discs and tainted items are Misc in the game, so they can
	 * only be told apart by their names.
	 */
	public static function fromTemplate(int $itemClass, string $name): self {
		$type = self::fromItemClass($itemClass);
		if ($type !== self::Misc) {
			return $type;
		}
		if (Safe::pregMatches(self::TAINTED_PATTERN, $name)) {
			return self::Tainted;
		}
		if (Safe::pregMatches(self::CRYSTAL_PATTERN, $name)) {
			return self::Crystal;
		}
		if (Safe::pregMatches(self::DISC_PATTERN, $name)) {
			return self::Disc;
		}
		return self::Misc;
	}

	case Misc = 'Misc';
	case Armor = 'Armor';
	case Implant = 'Implant';
	case Crystal = 'Crystal';
	case Weapon = 'Weapon';
	case Disc = 'Disc';
	case Spirit = 'Spirit';
	case Tainted = 'Tainted';
}
