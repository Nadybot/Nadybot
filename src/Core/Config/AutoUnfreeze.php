<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\Attributes\Hydrator\ConvertToBool;

/** Settings for the auto-unfreezer */
class AutoUnfreeze {
	/**
	 * @param bool $enabled      Enable automatic unfreezing of frozen accounts
	 * @param bool $useNadyproxy If set, use a public proxy to unfreeze. Funcom doesn't allow
	 *                           more than 5 unfreezing actions per IP address per 24h,
	 *                           so if you run the bot on a machine with other bots,
	 *                           using one of our public proxies will work around this.
	 */
	public function __construct(
		#[ConvertToBool] public bool $enabled=false,
		#[ConvertToBool] public bool $useNadyproxy=true,
	) {
	}
}
