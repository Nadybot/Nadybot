<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

/**
 * This is the list of all players considered to be online by the bot
 */
class OnlinePlayers {
	/**
	 * @param OnlinePlayer[] $org             All players online in the org
	 * @param OnlinePlayer[] $private_channel All players online in the private channel
	 *
	 * @psalm-param list<OnlinePlayer> $org
	 * @psalm-param list<OnlinePlayer> $private_channel
	 */
	public function __construct(
		public array $org=[],
		public array $private_channel=[],
	) {
	}
}
