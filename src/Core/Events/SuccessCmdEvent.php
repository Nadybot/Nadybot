<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** A command was successfully executed */
#[Event(mask: 'command(success)')]
class SuccessCmdEvent extends CmdEvent {
}
