<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Event;

/** Someone is removed from the member list of the bot */
class MemberRemoveEvent extends Event {
	public const EVENT_MASK = 'member(rem)';

	/** @param string $sender The player removed from members */
	public function __construct(
		public string $sender,
	) {
		$this->type = self::EVENT_MASK;
	}
}
