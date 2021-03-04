<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class Flag {
	public const VISIBLE = 0x00000001;
	public const MODIFIED_DESCRIPTION = 0x00000002;
	public const CAN_BE_TEMPLATE_ITEM = 0x00000008;
	public const TURN_ON_USE = 0x00000010;
	public const HAS_MULTIPLE_COUNT = 0x00000020;
	public const NODROP  = 0x04000000;
	public const UNIQUE  = 0x08000000;
}
