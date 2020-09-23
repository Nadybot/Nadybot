<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class LeaderEvent extends Event {
	/**
	 * The names of the leader
	 */
	public string $player;
}