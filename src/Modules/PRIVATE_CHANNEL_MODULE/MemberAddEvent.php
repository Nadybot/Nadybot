<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Event;

/** Someone is added to the member list of the bot */
class MemberAddEvent extends Event {
	public const EVENT_MASK = 'member(add)';

	/** @param string $sender The player added to members */
	public function __construct(
		public string $sender,
	) {
		$this->type = self::EVENT_MASK;
	}
}
