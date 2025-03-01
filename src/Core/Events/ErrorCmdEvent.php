<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Fired when a command errors */
#[Event(mask: 'command(error)')]
class ErrorCmdEvent extends CmdEvent {
}
