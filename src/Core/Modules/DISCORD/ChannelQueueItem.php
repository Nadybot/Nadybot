<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Revolt\EventLoop\Suspension;

class ChannelQueueItem {
	/** @param null|Suspension<void> $callback */
	public function __construct(
		public string $channelId,
		public string $message,
		public ?Suspension $callback=null,
	) {
	}
}
