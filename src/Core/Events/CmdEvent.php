<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\CommandHandler;

/** This is the base class for all command-events */
#[Event(mask: 'command(*)')]
abstract class CmdEvent {
	/**
	 * @param string              $sender     The character trying to execute a command
	 * @param string              $channel    The channel on which the command was received
	 * @param string              $cmd        The actual command
	 * @param CommandHandler|null $cmdHandler The command handler responsible for the command,
	 *                                        or `null` if none was found.
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public string $cmd,
		public ?CommandHandler $cmdHandler=null,
	) {
	}
}
