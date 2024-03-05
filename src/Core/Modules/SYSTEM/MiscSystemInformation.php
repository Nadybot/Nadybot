<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class MiscSystemInformation {
	/** Is the bot using a chat proxy for mass messages or more than 1000 friends */
	public bool $using_chat_proxy;

	/** Number of seconds since the bot was started */
	public int $uptime;
}
