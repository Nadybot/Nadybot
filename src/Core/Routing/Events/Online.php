<?php declare(strict_types=1);

namespace Nadybot\Core\Routing\Events;

use Nadybot\Core\Routing\Character;

class Online extends Base {
	public const TYPE = "online";

	public Character $char;
	public string $main;
	public string $type = self::TYPE;
	public bool $online = true;
	public bool $renderPath = false;
}
