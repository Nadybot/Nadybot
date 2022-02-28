<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class ChannelCommandStats {
	/** Name of the channel */
	public string $name;

	/** Number of active commands in this channel */
	public int $active_commands;
}
