<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'command(forbidden)')]
class ForbiddenCmdEvent extends CmdEvent {
}
