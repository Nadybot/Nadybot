<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

class DiscordActionRowComponent extends DiscordComponent {
	public int $type = 1;

	/** @var \Nadybot\Core\Modules\DISCORD\DiscordComponent[] */
	public array $components = [];
}
