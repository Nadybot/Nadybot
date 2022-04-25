<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Nadybot\Core\JSONDataModel;

class DiscordSessionStartLimit extends JSONDataModel {
	/** The total number of session starts the current user is allowed */
	public int $total;

	/** The remaining number of session starts the current user is allowed */
	public int $remaining;

	/** The number of milliseconds after which the limit resets */
	public int $reset_after;

	/** The number of identify requests allowed per 5 seconds */
	public int $max_concurrency;
}
