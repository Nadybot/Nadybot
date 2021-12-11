<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\Modules\DISCORD\DiscordMessageIn;

class DiscordMessageEvent extends AOChatEvent {
	public DiscordMessageIn $discord_message;
}
