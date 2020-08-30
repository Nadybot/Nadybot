<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

trait TowerVictoryTrait {
	public int $id;
	public int $time;
	public ?string $win_guild_name = null;
	public ?string $win_faction = null;
	public ?string $lose_guild_name = null;
	public ?string $lose_faction = null;
	public int $attack_id;
}
