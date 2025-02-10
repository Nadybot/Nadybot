<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Nadybot\Core\Types\Skill;

enum NanoSkill: int {
	case MM = Skill::MM->value;
	case PM = Skill::PM->value;
	case BM = Skill::BM->value;
	case SI = Skill::SI->value;
	case TS = Skill::TS->value;
	case MC = Skill::MC->value;
}
