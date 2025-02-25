<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** A message on the tower public channel */
#[Event(mask: 'towers')]
class TowersChannelMsgEvent extends PublicChannelMsgEvent {
}
