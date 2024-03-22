<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class Flag {
	public const VISIBLE                 = 0x00_00_00_01;
	public const MODIFIED_DESCRIPTION    = 0x00_00_00_02;
	public const MODIFIED_NAME           = 0x00_00_00_04;
	public const CAN_BE_TEMPLATE_ITEM    = 0x00_00_00_08;
	public const TURN_ON_USE             = 0x00_00_00_10;
	public const HAS_MULTIPLE_COUNT      = 0x00_00_00_20;
	public const LOCKED                  = 0x00_00_00_40;
	public const OPEN                    = 0x00_00_00_80;
	public const SOCIAL_ARMOR            = 0x00_00_01_00;
	public const TELL_COLLISION          = 0x00_00_02_00;
	public const NO_SELECTION_INDICATOR  = 0x00_00_04_00;
	public const USE_EMPTY_DESTRUCT      = 0x00_00_08_00;
	public const STATIONARY              = 0x00_00_10_00;
	public const REPULSIVE               = 0x00_00_20_00;
	public const DEFAULT_TARGET          = 0x00_00_40_00;
	public const TEXTURE_OVERRIDE        = 0x00_00_80_00;
	public const HAS_ANIMATION           = 0x00_02_00_00;
	public const HAS_ROTATION            = 0x00_04_00_00;
	public const WANT_COLLISION          = 0x00_08_00_00;
	public const WANT_SIGNALS            = 0x00_10_00_00;
	public const HAS_SENT_FIRST_IIR      = 0x00_20_00_00;
	public const HAS_ENERGY              = 0x00_40_00_00;
	public const MIRROR_IN_LEFT_HAND     = 0x00_80_00_00;
	public const ILLEGAL_CLAN            = 0x01_00_00_00;
	public const ILLEGAL_OMNI            = 0x02_00_00_00;
	public const NODROP                  = 0x04_00_00_00;
	public const UNIQUE                  = 0x08_00_00_00;
	public const CAN_BE_ATTACKED         = 0x10_00_00_00;
	public const DISABLE_FALLING         = 0x20_00_00_00;
	public const HAS_DAMAGE              = 0x40_00_00_00;
	public const DISABLE_STATE_COLLISION = 0x80_00_00_00;
}
