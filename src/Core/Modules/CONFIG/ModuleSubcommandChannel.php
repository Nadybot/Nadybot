<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

class ModuleSubcommandChannel {

	/**
	 * The access level you need to have
	 * in order to be allowed to use this command in this channel
	 */
	public string $access_level;

	/** Can this command be used in this channel? */
	public bool $enabled = false;
}
