<?php declare(strict_types=1);

namespace Nadybot\Core;

class CmdEvent extends Event {
	public const EVENT_MASK = "command(*)";

	/**
	 * @param string          $sender     Either the name of the sender or the numeric UID (eg. city raid accouncements)
	 * @param string          $channel    Where was the command received
	 * @param string          $cmd        The actual command
	 * @param string          $type       The full type
	 * @param ?CommandHandler $cmdHandler The command handler that will be/was used to execute the command
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public string $cmd,
		public ?CommandHandler $cmdHandler,
		string $type="command(unknown)",
	) {
		$this->type = $type;
	}
}
