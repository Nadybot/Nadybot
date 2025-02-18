<?php declare(strict_types=1);

namespace Nadybot\Core;

enum UnfreezeResult {
	case Failure;
	case Success;
	case TempError;
}
