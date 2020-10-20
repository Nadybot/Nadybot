<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class ConfigStatistics {
	/** Number of commands activated for use with /tell */
	public int $active_tell_commands = 0;

	/** Number of commands activated for use in the private channel */
	public int $active_priv_commands = 0;

	/** Number of commands activated for use in the org channel */
	public int $active_org_commands = 0;

	/** Number of subcommands activated */
	public int $active_subcommands = 0;

	/** Number of aliases */
	public int $active_aliases = 0;

	/** Number of currently active events */
	public int $active_events = 0;

	/** Number of active help texts for commands */
	public int $active_help_commands = 0;
}
