<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class SystemStats {
	/**
	 * @param int $buddy_list_size     How many characters are currently on the friendlist
	 * @param int $max_buddy_list_size Maximum allowed characters for the friendlist
	 * @param int $priv_channel_size   How many people are currently on the bot's private channel
	 * @param int $org_size            How many people are in the bot's org? 0 if not in an org
	 * @param int $charinfo_cache_size How many character data are currently cached?
	 * @param int $chatqueue_length    How many messages are waiting to be sent?
	 */
	public function __construct(
		public int $buddy_list_size=0,
		public int $max_buddy_list_size=0,
		public int $priv_channel_size=0,
		public int $org_size=0,
		public int $charinfo_cache_size=0,
		public int $chatqueue_length=0,
	) {
	}
}
