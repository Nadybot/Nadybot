<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\DBSchema\OnlinePlayer;

/**
 * This is the list of all players considered to be online by the bot
 * @package Nadybot\Modules\ONLINE_MODULE
 */
class OnlinePlayers {
	/**
	 * All players online in the org
	 * @var OnlinePlayer[]
	 */
	public array $org = [];

	/**
	 * All players online in the private channel
	 * @var OnlinePlayer[]
	 */
	public array $private_channel = [];
}
