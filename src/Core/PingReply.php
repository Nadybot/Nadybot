<?php declare(strict_types=1);

namespace Nadybot\Core;

class PingReply extends ProxyReply {
	/** The worker that replied */
	public int $worker = 0;
}
