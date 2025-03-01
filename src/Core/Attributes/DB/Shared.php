<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\DB;

/** Defined whether a database is shared between multiple bots */
enum Shared: int {
	case Yes = 1;
	case No = 0;
	case Both = 2;
}
