<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'discord_voice_leave')]
class DiscordVoiceLeaveEvent extends DiscordVoiceEvent {
}
