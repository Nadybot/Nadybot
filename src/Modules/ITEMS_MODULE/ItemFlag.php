<?php

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemFlag {
	public const VISIBLE                  = 1 << 0;
	public const MODIFIED_DESCRIPTION     = 1 << 1;
	public const CAN_BE_TEMPLATE_ITEM     = 1 << 3;
	public const TURN_ON_USE              = 1 << 4;
	public const HAS_MULTIPLE_COUNT       = 1 << 5;
	public const ITEM_SOCIAL_ARMOUR       = 1 << 8;
	public const TELL_COLLISION           = 1 << 9;
	public const NO_SELECTION_INDICATOR   = 1 << 10;
	public const USE_EMPTY_DESTRUCT       = 1 << 11;
	public const STATIONARY               = 1 << 12;
	public const REPULSIVE                = 1 << 13;
	public const DEFAULT_TARGET           = 1 << 14;
	public const NULL                     = 1 << 16;
	public const HAS_ANIMATION            = 1 << 17;
	public const HAS_ROTATION             = 1 << 18;
	public const WANT_COLLISION           = 1 << 19;
	public const WANT_SIGNALS             = 1 << 20;
	public const HAS_ENERGY               = 1 << 22;
	public const MIRROR_IN_LEFT_HAND      = 1 << 23;
	public const ILLEGAL_CLAN             = 1 << 24;
	public const ILLEGAL_OMNI             = 1 << 25;
	public const NO_DROP                  = 1 << 26;
	public const UNIQUE                   = 1 << 27;
	public const CAN_BE_ATTACKED          = 1 << 28;
	public const DISABLE_FALLING          = 1 << 29;
	public const HAS_DAMAGE               = 1 << 30;
	public const DISABLE_STATEL_COLLISION = 1 << 31;
}
