<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

class CmdEvent extends SayEvent {
	public const EVENT_MASK = 'leadercmd';

	/**
	 * @param string $player  The names of the sender
	 * @param string $message The message that was sent
	 */
	public function __construct(
		public string $player,
		public string $message,
	) {
		$this->type = self::EVENT_MASK;
	}
}
