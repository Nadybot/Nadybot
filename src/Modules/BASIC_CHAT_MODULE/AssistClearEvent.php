<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class AssistClearEvent extends Event {
	/** @param CallerList[] $lists An empty list */
	public function __construct(
		public array $lists=[],
	) {
		$this->type = "assist(clear)";
	}
}
