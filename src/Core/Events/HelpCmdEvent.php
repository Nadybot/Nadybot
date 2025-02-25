<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Someone executed an command with wrong parameters and was shown the help */
#[Event(mask: 'command(help)')]
class HelpCmdEvent extends CmdEvent {
}
