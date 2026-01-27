<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents a single item property */
enum ItemProperty: int {
	/**
	 * Create a new EnumBitfield from the given integer value
	 *
	 * @return EnumBitfield<self>
	 */
	public static function fromInt(int $flags): EnumBitfield {
		return (new EnumBitfield(self::class))->setInt($flags);
	}

	/**
	 * Check if an item property is set in the given value
	 *
	 * @param self $flag  The ItemProperty to search
	 * @param int  $value The value in which to search
	 */
	public static function has(self $flag, int $value): bool {
		return ($value & $flag->value) !== 0;
	}

	/**
	 * Check if this item property is set in the given value
	 *
	 * @param int $value The value in which to search
	 */
	public function in(int $value): bool {
		return ($value & $this->value) !== 0;
	}

	/**
	 * Check if this item property is not set in the given value
	 *
	 * @param int $value The value in which to search
	 */
	public function notIn(int $value): bool {
		return ($value & $this->value) === 0;
	}

	case CARRY = 1 << 0;
	case SIT = 1 << 1;
	case WEAR = 1 << 2;
	case USE = 1 << 3;
	case CONFIRM_USE = 1 << 4;
	case CONSUME = 1 << 5;
	case TUTOR_CHIP = 1 << 6;
	case TUTOR_DEVICE = 1 << 7;
	case BREAKING_AND_ENTERING = 1 << 8;
	case STACKABLE = 1 << 9;
	case NO_AMMO = 1 << 10;
	case BURST = 1 << 11;
	case FLING_SHOT = 1 << 12;
	case FULL_AUTO = 1 << 13;
	case AIMED_SHOT = 1 << 14;
	case BOW = 1 << 15;
	case THROW_ATTACK = 1 << 16;
	case SNEAK_ATTACK = 1 << 17;
	case FAST_ATTACK = 1 << 18;
	case DISARM_TRAPS = 1 << 19;
	case AUTO_SELECT = 1 << 20;
	case APPLY_ON_FRIENDLY = 1 << 21;
	case APPLY_ON_HOSTILE = 1 << 22;
	case APPLY_ON_SELF = 1 << 23;
	case CANT_SPLIT = 1 << 24;
	case BRAWL = 1 << 25;
	case DIMACH = 1 << 26;
	case ENABLE_HAND_ATTRACTORS = 1 << 27;
	case CAN_BE_WORN_WITH_SOCIAL_ARMOR = 1 << 28;
	case CAN_PARRY_RIPOSITE = 1 << 29;
	case CAN_BE_PARRIED_RIPOSITED = 1 << 30;
	case APPLY_ON_FIGHTING_TARGET = 1 << 31;
}
