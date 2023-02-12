<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class Flag {
	public const VISIBLE                 = 0x00000001;
	public const MODIFIED_DESCRIPTION    = 0x00000002;
	public const MODIFIED_NAME           = 0x00000004;
	public const CAN_BE_TEMPLATE_ITEM    = 0x00000008;
	public const TURN_ON_USE             = 0x00000010;
	public const HAS_MULTIPLE_COUNT      = 0x00000020;
	public const LOCKED                  = 0x00000040;
	public const OPEN                    = 0x00000080;
	public const SOCIAL_ARMOR            = 0x00000100;
	public const TELL_COLLISION          = 0x00000200;
	public const NO_SELECTION_INDICATOR  = 0x00000400;
	public const USE_EMPTY_DESTRUCT      = 0x00000800;
	public const STATIONARY              = 0x00001000;
	public const REPULSIVE               = 0x00002000;
	public const DEFAULT_TARGET          = 0x00004000;
	public const TEXTURE_OVERRIDE        = 0x00008000;
	public const HAS_ANIMATION           = 0x00020000;
	public const HAS_ROTATION            = 0x00040000;
	public const WANT_COLLISION          = 0x00080000;
	public const WANT_SIGNALS            = 0x00100000;
	public const HAS_SENT_FIRST_IIR      = 0x00200000;
	public const HAS_ENERGY              = 0x00400000;
	public const MIRROR_IN_LEFT_HAND     = 0x00800000;
	public const ILLEGAL_CLAN            = 0x01000000;
	public const ILLEGAL_OMNI            = 0x02000000;
	public const NODROP                  = 0x04000000;
	public const UNIQUE                  = 0x08000000;
	public const CAN_BE_ATTACKED         = 0x10000000;
	public const DISABLE_FALLING         = 0x20000000;
	public const HAS_DAMAGE              = 0x40000000;
	public const DISABLE_STATE_COLLISION = 0x80000000;
}
