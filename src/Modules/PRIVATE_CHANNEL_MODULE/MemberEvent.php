<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'member(*)')]
abstract class MemberEvent {
	/** @param string $sender The player added or removed from members */
	public function __construct(
		public string $sender,
	) {
	}
}
