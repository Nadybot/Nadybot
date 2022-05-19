<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Closure;

class ChannelQueueItem {
	public function __construct(
		public string $channelId,
		public string $message,
		public ?Closure $callback=null,
	) {
	}
}
