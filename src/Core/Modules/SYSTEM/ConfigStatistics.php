<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class ConfigStatistics {
	/**
	 * Number of commands activated for each channel
	 * @var ChannelCommandStats[]
	 */
	public array $active_commands = [];

	/** Number of subcommands activated */
	public int $active_subcommands = 0;

	/** Number of aliases */
	public int $active_aliases = 0;

	/** Number of currently active events */
	public int $active_events = 0;

	/** Number of active help texts for commands */
	public int $active_help_commands = 0;
}
