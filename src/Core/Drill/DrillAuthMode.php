<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

enum DrillAuthMode: int {
	case STATIC = 1;
	case ANONYMOUS = 2;
	case AO_TELL = 3;
}
