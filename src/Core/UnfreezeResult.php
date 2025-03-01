<?php declare(strict_types=1);

namespace Nadybot\Core;

/** The resultg of an account unfreeze action */
enum UnfreezeResult {
	case Failure;
	case Success;
	case TempError;
}
