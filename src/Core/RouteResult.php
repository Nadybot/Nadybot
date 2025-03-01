<?php declare(strict_types=1);

namespace Nadybot\Core;

/** The possible results of routing a message/event via the message hub */
enum RouteResult {
	case Routed;
	case Discarded;
	case Delivered;
}
