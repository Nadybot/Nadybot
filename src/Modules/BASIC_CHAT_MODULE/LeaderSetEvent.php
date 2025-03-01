<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'leader(set)')]
class LeaderSetEvent extends LeaderEvent {
}
