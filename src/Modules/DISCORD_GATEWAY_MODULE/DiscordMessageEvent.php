<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Event;
use Nadybot\Core\Modules\DISCORD\DiscordMessageIn;

class DiscordMessageEvent extends Event {
	public DiscordMessageIn $discord_message;
}
