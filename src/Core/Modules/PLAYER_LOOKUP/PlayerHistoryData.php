<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use DateTime;
use Nadybot\Core\JSONDataModel;

class PlayerHistoryData extends JSONDataModel {
	public string $nickname;
	public string $level;
	public string $breed;
	public string $gender;
	public string $defender_rank;
	public ?string $guild_rank_name;
	public ?string $guild_name;
	public DateTime $last_changed;
	public $faction;
}
