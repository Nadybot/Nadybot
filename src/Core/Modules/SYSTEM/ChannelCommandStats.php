<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

/** Name and number of active commands for a configure command channel */
class ChannelCommandStats {
	/**
	 * @param string $name            Name of the channel
	 * @param int    $active_commands Number of active commands in this channel
	 */
	public function __construct(
		public string $name,
		public int $active_commands,
	) {
	}
}
