<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\Attributes\JSON;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Types\{Faction, Profession};

/**
 * This represents a single player in the online list
 *
 * @package Nadybot\Modules\ONLINE_MODULE
 */
class OnlinePlayer extends Player {
	/**
	 * @param int         $charid        The character ID as used by Anarchy Online
	 * @param string      $name          The character's name as it appears in the game
	 * @param string      $pmain         The name of the main character, or the same as $name if this is the main character of the player
	 * @param ?int        $dimension     In which dimension (RK server) is this character? 4 for test, 5 for RK5, 6 for RK19
	 * @param string      $firstname     The character's first name (the name before $name)
	 * @param string      $lastname      The character's last name (the name after $name)
	 * @param ?int        $level         What level (1-220) is the character or null if unknown
	 * @param string      $breed         Any of Nano, Solitus, Atrox or Opifex. Also empty string if unknown
	 * @param string      $gender        Male, Female, Neuter or an empty string if unknown
	 * @param Faction     $faction       Omni, Clan, Neutral or an empty string if unknown
	 * @param ?Profession $profession    The long profession name (e.g. "Enforcer", not "enf" or "enfo") or an empty string if unknown
	 * @param string      $prof_title    The title-level title for the profession of this player For example "The man", "Don" or empty if unknown.
	 * @param string      $ai_rank       The name of the ai_level as a rank or empty string if unknown
	 * @param ?int        $ai_level      AI level of this player or null if unknown
	 * @param ?int        $guild_id      The id of the org this player is in or null if none or unknown
	 * @param ?string     $guild         The name of the org this player is in or null if none/unknown
	 * @param ?string     $guild_rank    The name of the rank the player has in their org (Veteran, Apprentice) or null if not in an org or unknown
	 * @param ?int        $guild_rank_id The numeric rank of the player in their org or null if not in an org/unknown
	 * @param ?int        $head_id       Which head is the player using
	 * @param ?int        $pvp_rating    Numeric PvP-rating of the player (1-7) or null if unknown
	 * @param ?string     $pvp_title     Name of the player's PvP title derived from their $pvp_rating or null if unknown
	 * @param string      $source        Source of the information
	 * @param ?int        $last_update   Unix timestamp of the last update of these data
	 * @param string      $afk           The AFK message of the player or an empty string
	 * @param ?string     $nick          The nickname of the main character, or null if unset
	 * @param bool        $online        True if this player is currently online, false otherwise
	 */
	final public function __construct(
		int $charid,
		string $name,
		#[JSON\Name('main_character')] public string $pmain,
		?int $dimension=null,
		string $firstname='',
		string $lastname='',
		?int $level=null,
		string $breed='',
		string $gender='',
		Faction $faction=Faction::Unknown,
		?Profession $profession=Profession::Unknown,
		string $prof_title='',
		string $ai_rank='',
		?int $ai_level=null,
		?int $guild_id=null,
		?string $guild='',
		?string $guild_rank='',
		?int $guild_rank_id=null,
		?int $head_id=null,
		?int $pvp_rating=null,
		?string $pvp_title=null,
		string $source='',
		?int $last_update=null,
		#[JSON\Name('afk_message')] public string $afk='',
		#[JSON\Name('nickname')] public ?string $nick=null,
		public bool $online=false,
	) {
		parent::__construct(
			charid: $charid,
			name: $name,
			dimension: $dimension,
			firstname: $firstname,
			lastname: $lastname,
			level: $level,
			breed: $breed,
			gender: $gender,
			faction: $faction,
			profession: $profession,
			prof_title: $prof_title,
			ai_rank: $ai_rank,
			ai_level: $ai_level,
			guild_id: $guild_id,
			guild: $guild,
			guild_rank: $guild_rank,
			guild_rank_id: $guild_rank_id,
			head_id: $head_id,
			pvp_rating: $pvp_rating,
			pvp_title: $pvp_title,
			source: $source,
			last_update: $last_update,
		);
	}

	public static function fromPlayer(?Player $player=null, ?Online $online=null): static {
		if (!isset($player) && !isset($online)) {
			throw new \InvalidArgumentException(__CLASS__ . '::' . __FUNCTION__ . '() requires at least onr of $player or $online');
		}
		if (!isset($player)) {
			$op = [
				'charid' => 0,
				'name' => $online->name,
			];
		} else {
			$op = get_object_vars($player);
		}
		if (isset($online)) {
			$op['online'] = true;
			$op['name'] = $online->name;
			$op['afk'] = $online->afk ?? '';
		}
		$op['pmain'] = $op['name'];
		return new static(...$op);
	}

	public function updateWith(Player $player): static {
		return new static(
			charid: $player->charid,
			name: $player->name,
			pmain: $this->pmain,
			dimension: $player->dimension,
			firstname: $player->firstname,
			lastname: $player->lastname,
			level: $player->level ?? $this->level,
			breed: $player->breed,
			gender: $player->gender,
			faction: $player->faction,
			profession: $player->profession,
			prof_title: $player->prof_title,
			ai_rank: $player->ai_rank,
			ai_level: $player->ai_level,
			guild_id: $player->guild_id,
			guild: $player->guild,
			guild_rank: $player->guild_rank,
			guild_rank_id: $player->guild_rank_id,
			head_id: $player->head_id,
			pvp_rating: $player->pvp_rating,
			pvp_title: $player->pvp_title,
			last_update: $player->last_update,
			afk: $this->afk,
			nick: $this->nick,
			online: $this->online,
			source: $player->source ?? $this->source,
		);
	}
}
