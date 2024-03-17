<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class AssistSetEvent extends Event {
	/** @param CallerList[] $lists The names of the players on the assist list */
	public function __construct(
		public array $lists=[],
	) {
		$this->type = "assist(set)";
	}
}
