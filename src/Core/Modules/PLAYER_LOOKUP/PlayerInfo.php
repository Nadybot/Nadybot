<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use DateTimeImmutable;
use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastToDateTimeImmutable;
use Nadybot\Core\DBSchema\Player;

/**
 * This represents the data the highway feed gives for a player
 */
class PlayerInfo {
	/**
	 * @param int               $uid         The character ID as used by Anarchy Online
	 * @param string            $firstName   The character's first name (the name before $name)
	 * @param string            $name        The character's name as it appears in the game
	 * @param string            $lastName    The character's last name (the name after $name)
	 * @param int               $level       What level (1-220) is the character or null if unknown
	 * @param string            $breed       Any of Nano, Solitus, Atrox or Opifex. Also empty string if unknown
	 * @param string            $gender      Male, Female, Neuter or an empty string if unknown
	 * @param string            $faction     Omni, Clan, Neutral or an empty string if unknown
	 * @param string            $profession  The long profession name (e.g. "Enforcer", not "enf" or "enfo") or an empty string if unknown
	 * @param string            $profTitle   The title-level title for the profession of this player For example "The man", "Don" or empty if unknown.
	 * @param string            $aiRank      The name of the $aiLevel as a rank or empty string if unknown
	 * @param int               $aiLevel     AI level of this player or null if unknown
	 * @param ?int              $orgId       The id of the org this player is in or null if none
	 * @param ?string           $orgName     The name of the org this player is in or null if none
	 * @param ?string           $orgRank     The name of the rank the player has in their org (Veteran, Apprentice) or null if not in an org
	 * @param ?int              $orgRankId   The numeric rank of the player in their org or null if not in an org
	 * @param int               $dimension   In which dimension (RK server) is this character? 4 for test, 5 for RK5, 6 for RK19
	 * @param int               $headMesh    Which head is the player using
	 * @param ?int              $pvpRating   Numeric PvP-rating of the player (1-7) or null if unknown
	 * @param ?string           $pvpTitle    Name of the player's PvP title derived from their $pvpRating or null if unknown
	 * @param DateTimeImmutable $lastUpdated Point in time from which this information is
	 */
	public function __construct(
		#[MapFrom('0.CHAR_INSTANCE', '.')] public int $uid,
		#[MapFrom('0.FIRSTNAME', '.')] public string $firstName,
		#[MapFrom('0.NAME', '.')] public string $name,
		#[MapFrom('0.LASTNAME', '.')] public string $lastName,
		#[MapFrom('0.LEVELX', '.')] public int $level,
		#[MapFrom('0.BREED', '.')] public string $breed,
		#[MapFrom('0.SEX', '.')] public string $gender,
		#[MapFrom('0.SIDE', '.')] public string $faction,
		#[MapFrom('0.PROF', '.')] public string $profession,
		#[MapFrom('0.PROFNAME', '.')] public string $profTitle,
		#[MapFrom('0.RANK_name', '.')] public string $aiRank,
		#[MapFrom('0.ALIENLEVEL', '.')] public int $aiLevel,
		#[MapFrom('1.ORG_INSTANCE', '.')] public ?int $orgId,
		#[MapFrom('1.NAME', '.')] public ?string $orgName,
		#[MapFrom('1.RANK_TITLE', '.')] public ?string $orgRank,
		#[MapFrom('1.RANK', '.')] public ?int $orgRankId,
		#[MapFrom('0.CHAR_DIMENSION', '.')] public int $dimension,
		#[MapFrom('0.HEADID', '.')] public int $headMesh,
		#[MapFrom('0.PVPRATING', '.')] public ?int $pvpRating,
		#[MapFrom('0.PVPTITLE', '.')] public ?string $pvpTitle,
		#[MapFrom('2')] #[CastToDateTimeImmutable('Y/m/d H:i:s', 'UTC')] public DateTimeImmutable $lastUpdated,
	) {
	}

	public function toPlayer(): Player {
		return new Player(
			ai_level: $this->aiLevel,
			ai_rank: $this->aiRank,
			breed: $this->breed ?? '',
			charid: $this->uid,
			dimension: $this->dimension,
			faction: $this->faction ?? '',
			firstname: $this->firstName,
			gender: $this->gender ?? '',
			guild: $this->orgName ?? '',
			guild_id: $this->orgId ?? 0,
			guild_rank: $this->orgRank ?? '',
			guild_rank_id: $this->orgRankId,
			head_id: $this->headMesh,
			last_update: $this->lastUpdated->getTimestamp(),
			lastname: $this->lastName,
			level: $this->level,
			name: $this->name,
			prof_title: $this->profTitle ?? '',
			profession: $this->profession,
			pvp_rating: $this->pvpRating,
			pvp_title: $this->pvpTitle,
			source: 'AO',
		);
	}
}
