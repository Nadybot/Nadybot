<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** Represent enabled or disabled */
enum Status: int {
	case Enabled = 1;
	case Disabled = 0;
}
