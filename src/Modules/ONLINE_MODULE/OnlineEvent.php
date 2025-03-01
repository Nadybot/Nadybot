<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Types\EventInterface;

#[Event(mask: 'online(*)')]
class OnlineEvent implements EventInterface {
	public function __construct(
		public OnlinePlayer $player,
		public string $channel,
	) {
	}

	public function getEvent(): string {
		return "online({$this->channel})";
	}
}
