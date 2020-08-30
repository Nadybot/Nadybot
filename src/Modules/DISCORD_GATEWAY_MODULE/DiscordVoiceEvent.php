<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Event;
use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;

class DiscordVoiceEvent extends Event {
	public DiscordChannel $discord_channel;
	public GuildMember $member;
}
