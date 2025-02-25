<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** A system-message was send in our org's system-channel */
#[Event(mask: 'orgmsg')]
class OrgMsgChannelMsgEvent extends PublicChannelMsgEvent {
}
