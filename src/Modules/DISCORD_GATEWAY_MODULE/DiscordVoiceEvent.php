<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Modules\DISCORD\{DiscordChannel, GuildMember};

#[Event(mask: 'discord_voice_*')]
abstract class DiscordVoiceEvent {
	public function __construct(
		public DiscordChannel $discord_channel,
		public GuildMember $member,
	) {
	}
}
