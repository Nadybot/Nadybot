<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Closure;

class ChannelQueueItem {
	public ?Closure $callback = null;

	public function __construct(
		public string $channelId,
		public string $message,
		?callable $callback=null,
	) {
		if (isset($callback)) {
			$this->callback = Closure::fromCallable($callback);
		}
	}
}
