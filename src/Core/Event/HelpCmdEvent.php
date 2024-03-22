<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\{CmdEvent, CommandHandler};

class HelpCmdEvent extends CmdEvent {
	public const EVENT_MASK = 'command(help)';

	/**
	 * @param string         $sender     Either the name of the sender or the numeric UID (eg. city raid accouncements)
	 * @param string         $channel    Where was the command received
	 * @param string         $cmd        The actual command
	 * @param CommandHandler $cmdHandler The command handler that will be/was used to execute the command
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public string $cmd,
		CommandHandler $cmdHandler,
	) {
		$this->cmdHandler = $cmdHandler;
		$this->type = self::EVENT_MASK;
	}
}
