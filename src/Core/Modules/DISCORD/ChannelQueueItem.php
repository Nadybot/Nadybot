<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Amp\Deferred;

class ChannelQueueItem {
	/** @param null|Deferred<void> $callback */
	public function __construct(
		public string $channelId,
		public string $message,
		public ?Deferred $callback=null,
	) {
	}
}
