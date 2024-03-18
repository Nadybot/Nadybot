<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;

class DiscordVoiceLeaveEvent extends DiscordVoiceEvent {
	public const EVENT_MASK = "discord_voice_leave";

	public function __construct(
		public DiscordChannel $discord_channel,
		public GuildMember $member,
	) {
		$this->type = self::EVENT_MASK;
	}
}
