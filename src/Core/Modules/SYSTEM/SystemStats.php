<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class SystemStats {
	/** How many characters are currently on the friendlist */
	public int $buddy_list_size = 0;

	/** Maximum allowed characters for the friendlist */
	public int $max_buddy_list_size = 0;

	/** How many people are currently on the bot's private channel */
	public int $priv_channel_size = 0;

	/** How many people are in the bot's org? 0 if not in an org */
	public int $org_size = 0;

	/** How many character infos are currently cached? */
	public int $charinfo_cache_size = 0;

	/** How many messages are waiting to be sent? */
	public int $chatqueue_length = 0;
}
