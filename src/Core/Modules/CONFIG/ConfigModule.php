<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

class ConfigModule {
	/** Name of the module */
	public string $name;

	/** How many commands are enabled */
	public int $num_commands_enabled = 0;

	/** How many commands are disabled */
	public int $num_commands_disabled = 0;

	/** How many events are enabled */
	public int $num_events_enabled = 0;

	/** How many events are disabled */
	public int $num_events_disabled = 0;

	/** How many settings are there? */
	public int $num_settings = 0;
}
