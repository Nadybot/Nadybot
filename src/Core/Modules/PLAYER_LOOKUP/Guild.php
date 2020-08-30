<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\DBSchema\Player;

class Guild {
	public int $guild_id;
	public string $orgname;
	public string $orgside;
	/** @var array<string,Player> */
	public array $members = [];
}
