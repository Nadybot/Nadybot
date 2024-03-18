<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class AssistSetEvent extends Event {
	public const EVENT_MASK = "assist(set)";

	/** @param CallerList[] $lists The names of the players on the assist list */
	public function __construct(
		public array $lists=[],
	) {
		$this->type = self::EVENT_MASK;
	}
}
