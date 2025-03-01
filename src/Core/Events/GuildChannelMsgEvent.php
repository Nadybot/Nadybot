<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** A message was send in our org's channel */
#[Event(mask: 'guild')]
class GuildChannelMsgEvent extends PublicChannelMsgEvent {
}
