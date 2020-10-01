<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class AssistEvent extends Event {
	/**
	 * The names of the players added to the assist list, or empty on list clear
	 * @var CallerList[]
	 */
	public array $lists = [];
}