<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'assist(set)')]
class AssistSetEvent {
	/** @param list<CallerList> $lists The names of the players on the assist list */
	public function __construct(
		public array $lists=[],
	) {
	}
}
