<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** We receive a message on our channel */
#[Event(mask: 'priv')]
class MyPrivateChannelMsgEvent extends PrivateChannelMsgEvent {
}
