<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\DBRow;

class DBDiscordInvite extends DBRow {
	public int $id;
	public string $character;
	public string $token;
	public ?int $expires = null;
}
