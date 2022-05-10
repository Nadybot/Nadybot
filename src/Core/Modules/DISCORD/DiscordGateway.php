<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Nadybot\Core\JSONDataModel;

class DiscordGateway extends JSONDataModel {
	/** The WSS URL that can be used for connecting to the gateway */
	public string $url;

	/** The recommended number of shards to use when connecting */
	public int $shards;

	/** Information on the current session start limit */
	public DiscordSessionStartLimit $session_start_limit;
}
