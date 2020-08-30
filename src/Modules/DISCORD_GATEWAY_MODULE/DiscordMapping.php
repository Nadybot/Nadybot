<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\DBRow;

class DiscordMapping extends DBRow {
	public string $name;
	public string $discord_id;
	public ?string $token;
	public int $created;
	public ?int $confirmed;
}
