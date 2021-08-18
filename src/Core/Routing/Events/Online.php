<?php declare(strict_types=1);

namespace Nadybot\Core\Routing\Events;

use Nadybot\Core\Routing\Character;

class Online extends Base {
	public Character $char;
	public string $type = "online";
	public bool $online = true;
	public bool $renderPath = false;
}
