<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Event;

class MemberEvent extends Event {
	/** The player added or removed from members */
	public string $sender;
}
