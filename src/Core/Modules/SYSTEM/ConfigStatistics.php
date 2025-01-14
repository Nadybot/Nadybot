<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

/** Amount of active commands, aliases, events, etc. */
class ConfigStatistics {
	/**
	 * @param ChannelCommandStats[] $active_commands      Number of commands activated for each channel
	 * @param int                   $active_subcommands   Number of subcommands activated
	 * @param int                   $active_aliases       Number of aliases
	 * @param int                   $active_events        Number of currently active events
	 * @param int                   $active_help_commands Number of active help texts for commands
	 *
	 * @psalm-param list<ChannelCommandStats> $active_commands
	 */
	public function __construct(
		public array $active_commands=[],
		public int $active_subcommands=0,
		public int $active_aliases=0,
		public int $active_events=0,
		public int $active_help_commands=0,
	) {
	}
}
