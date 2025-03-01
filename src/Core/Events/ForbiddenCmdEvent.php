<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Fired when the execution of a command was forbidden */
#[Event(mask: 'command(forbidden)')]
class ForbiddenCmdEvent extends CmdEvent {
}
