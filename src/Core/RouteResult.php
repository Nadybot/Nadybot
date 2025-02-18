<?php declare(strict_types=1);

namespace Nadybot\Core;

enum RouteResult {
	case Routed;
	case Discarded;
	case Delivered;
}
