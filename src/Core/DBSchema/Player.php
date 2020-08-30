<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Player extends DBRow {
	public int $charid;
	public string $firstname = '';
	public string $name;
	public string $lastname = '';
	public ?int $level = null;
	public string $breed = '';
	public string $gender = '';
	public string $faction = '';
	public ?string $profession = '';
	public string $prof_title= '';
	public string $ai_rank = '';
	public ?int $ai_level = null;
	public ?int $guild_id = null;
	public ?string $guild = '';
	public ?string $guild_rank = '';
	public ?int $guild_rank_id = null;
	public ?int $dimension;
	public ?int $head_id = null;
	public ?int $pvp_rating = null;
	public ?string $pvp_title = null;
	public string $source = '';
	public ?int $last_update;
}
