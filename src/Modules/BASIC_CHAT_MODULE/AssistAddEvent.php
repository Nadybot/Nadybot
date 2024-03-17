<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class AssistAddEvent extends Event {
	/** @param CallerList[] $lists The names of the players added to the assist list */
	public function __construct(
		public array $lists=[],
	) {
		$this->type = "assist(add)";
	}
}
