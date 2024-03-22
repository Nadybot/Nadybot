<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class AssistClearEvent extends Event {
	public const EVENT_MASK = 'assist(clear)';

	/** @param CallerList[] $lists An empty list */
	public function __construct(
		public array $lists=[],
	) {
		$this->type = self::EVENT_MASK;
	}
}
