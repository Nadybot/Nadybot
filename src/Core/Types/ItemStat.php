<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/**
 * The stats of an item template that are not skills
 *
 * The values are the stat IDs the game uses. Stats that can be buffed,
 * like MultiMelee, are in Skill instead.
 */
enum ItemStat: int {
	/** Item flags, see ItemFlag */
	case Flags = 0;

	/** Item properties ("can" flags), see ItemProperty */
	case Can = 30;

	/** 0 = Misc, 1 = Weapon, 2 = Armor, 3 = Implant, 5 = Spirit */
	case ItemClass = 76;

	case Icon = 79;

	/** Time between two attacks in centiseconds */
	case AttackDelay = 294;

	/** Time to recharge after an attack in centiseconds */
	case RechargeDelay = 210;

	case BurstRecharge = 374;
	case FullAutoRecharge = 375;

	/** The slots the item can be equipped in, depending on the item class */
	case Slot = 298;
}
