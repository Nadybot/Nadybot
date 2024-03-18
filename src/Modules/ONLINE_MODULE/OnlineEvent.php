<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\Event;

class OnlineEvent extends Event {
	public const EVENT_MASK = "online(*)";

	public function __construct(
		public OnlinePlayer $player,
		public string $channel,
	) {
		$this->type = "online({$channel})";
	}
}
