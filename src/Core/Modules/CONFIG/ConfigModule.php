<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

/** Name, description and stats for a module */
class ConfigModule {
	/**
	 * @param string  $name                  Name of the module
	 * @param int     $num_commands_enabled  How many commands are enabled
	 * @param int     $num_commands_disabled How many commands are disabled
	 * @param int     $num_events_enabled    How many events are enabled
	 * @param int     $num_events_disabled   How many events are disabled
	 * @param int     $num_settings          How many settings are there?
	 * @param ?string $description           Description of the module or null if none
	 */
	public function __construct(
		public string $name,
		public int $num_commands_enabled=0,
		public int $num_commands_disabled=0,
		public int $num_events_enabled=0,
		public int $num_events_disabled=0,
		public int $num_settings=0,
		public ?string $description=null,
	) {
	}
}
