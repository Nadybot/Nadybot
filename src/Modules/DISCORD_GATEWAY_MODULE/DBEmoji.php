<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\DBRow;

class DBEmoji extends DBRow {
	public int $id;
	public string $name;
	public string $guild_id;
	public int $registered;
	public int $version;
}
