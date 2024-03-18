<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class SayEvent extends Event {
	public const EVENT_MASK = "leadersay";

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
