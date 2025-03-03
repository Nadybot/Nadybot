<?php declare(strict_types=1);

namespace Nadybot\Core;

/** The result of an account unfreeze action */
enum UnfreezeResult {
	case Failure;
	case Success;
	case TempError;
}
