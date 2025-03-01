<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Attributes\Event;

/** Someone is removed from the member list of the bot */
#[Event(mask: 'member(rem)')]
class MemberRemoveEvent extends MemberEvent {
}
