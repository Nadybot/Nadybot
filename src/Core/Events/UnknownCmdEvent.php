<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** A command that was answered with "did you mean …?" */
#[Event(mask: 'command(unknown)')]
class UnknownCmdEvent extends CmdEvent {
}
