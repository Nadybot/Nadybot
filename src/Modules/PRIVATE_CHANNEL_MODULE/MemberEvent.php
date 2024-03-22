<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Event;

abstract class MemberEvent extends Event {
	public const EVENT_MASK = 'member(*)';

	/** The player added or removed from members */
	public string $sender;
}
