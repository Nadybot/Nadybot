<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\Event;

class OnlineEvent extends Event {
	public OnlinePlayer $player;
	public string $channel;
}
