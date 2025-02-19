<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents a single item flag */
enum ItemFlag: int {
	/**
	 * Create a new EnumBitfield from the given integer value
	 *
	 * @return EnumBitfield<self>
	 */
	public static function fromInt(int $flags): EnumBitfield {
		return (new EnumBitfield(self::class))->setInt($flags);
	}

	/**
	 * Check if an item flag is set in the given value
	 *
	 * @param self $flag  The ItemFlag to search
	 * @param int  $value The value in which to search
	 */
	public static function has(self $flag, int $value): bool {
		return ($value & $flag->value) !== 0;
	}

	/**
	 * Check if this item flag is set in the given value
	 *
	 * @param int $value The value in which to search
	 */
	public function in(int $value): bool {
		return ($value & $this->value) !== 0;
	}

	/**
	 * Check if this item flag is not set in the given value
	 *
	 * @param int $value The value in which to search
	 */
	public function notIn(int $value): bool {
		return ($value & $this->value) === 0;
	}

	case VISIBLE                  = 1 << 0;
	case MODIFIED_DESCRIPTION     = 1 << 1;
	case CAN_BE_TEMPLATE_ITEM     = 1 << 3;
	case TURN_ON_USE              = 1 << 4;
	case HAS_MULTIPLE_COUNT       = 1 << 5;
	case ITEM_SOCIAL_ARMOUR       = 1 << 8;
	case TELL_COLLISION           = 1 << 9;
	case NO_SELECTION_INDICATOR   = 1 << 10;
	case USE_EMPTY_DESTRUCT       = 1 << 11;
	case STATIONARY               = 1 << 12;
	case REPULSIVE                = 1 << 13;
	case DEFAULT_TARGET           = 1 << 14;
	case NULL                     = 1 << 16;
	case HAS_ANIMATION            = 1 << 17;
	case HAS_ROTATION             = 1 << 18;
	case WANT_COLLISION           = 1 << 19;
	case WANT_SIGNALS             = 1 << 20;
	case HAS_ENERGY               = 1 << 22;
	case MIRROR_IN_LEFT_HAND      = 1 << 23;
	case ILLEGAL_CLAN             = 1 << 24;
	case ILLEGAL_OMNI             = 1 << 25;
	case NO_DROP                  = 1 << 26;
	case UNIQUE                   = 1 << 27;
	case CAN_BE_ATTACKED          = 1 << 28;
	case DISABLE_FALLING          = 1 << 29;
	case HAS_DAMAGE               = 1 << 30;
	case DISABLE_STATEL_COLLISION = 1 << 31;
}
