<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

/** System statistics that don't fit any other category */
class MiscSystemInformation {
	/**
	 * @param bool $using_chat_proxy Is the bot using a chat proxy for mass messages or more than 1000 friends
	 * @param int  $uptime           Number of seconds since the bot was started
	 */
	public function __construct(
		public bool $using_chat_proxy,
		public int $uptime,
	) {
	}
}
