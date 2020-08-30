<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

trait TowerAttackTrait {
	public int $id;
	public int $time;
	public ?string $att_guild_name = null;
	public ?string $att_faction = null;
	public ?string $att_player = null;
	public ?int $att_level = null;
	public ?int $att_ai_level = null;
	public ?string $att_profession = null;
	public ?string $def_guild_name = null;
	public ?string $def_faction = null;
	public ?int $playfield_id = null;
	public ?int $site_number = null;
	public ?int $x_coords = null;
	public ?int $y_coord = null;
}
