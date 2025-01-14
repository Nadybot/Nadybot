<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

/** The access level needed to execute a command, and if it's enabled at all */
class ModuleSubcommandChannel {
	/**
	 * @param string $access_level The access level you need to have
	 *                             in order to be allowed to use this command
	 *                             in this channel
	 * @param bool   $enabled      Can this command be used in this channel?
	 */
	public function __construct(
		public string $access_level,
		public bool $enabled,
	) {
	}
}
