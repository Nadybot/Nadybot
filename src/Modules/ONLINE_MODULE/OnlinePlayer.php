<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\DBSchema\Player;

class OnlinePlayer extends Player {
	public string $afk = '';
	public string $pmain;
	public bool $online = false;
}
