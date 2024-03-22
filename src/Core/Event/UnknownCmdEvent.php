<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\CmdEvent;

class UnknownCmdEvent extends CmdEvent {
	public const EVENT_MASK = 'command(unknown)';

	/**
	 * @param string $sender  Either the name of the sender or the numeric UID (eg. city raid accouncements)
	 * @param string $channel Where was the command received
	 * @param string $cmd     The actual command
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public string $cmd,
	) {
		$this->cmdHandler = null;
		$this->type = self::EVENT_MASK;
	}
}
