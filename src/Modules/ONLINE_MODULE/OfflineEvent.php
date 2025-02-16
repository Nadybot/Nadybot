<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Types\EventInterface;

#[Event(mask: 'offline(*)')]
class OfflineEvent implements EventInterface {
	public function __construct(
		public string $player,
		public string $channel,
	) {
	}

	public function getEvent(): string {
		return "offline({$this->channel})";
	}
}
