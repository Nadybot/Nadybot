<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use JsonException;
use Nadybot\Core\{
	AOChatPacket,
	CacheManager,
	DB,
	Nadybot,
};
use Nadybot\Core\DBSchema\Player;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class GuildManager {
	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public CacheManager $cacheManager;
	
	/** @Inject */
	public PlayerManager $playerManager;

	public function getById(int $guildID, int $dimension=null, bool $forceUpdate=false): ?Guild {
		// if no server number is specified use the one on which the bot is logged in
		$dimension ??= (int)$this->chatBot->vars["dimension"];
		
		$url = "http://people.anarchy-online.com/org/stats/d/$dimension/name/$guildID/basicstats.xml?data_type=json";
		$groupName = "guild_roster";
		$filename = "$guildID.$dimension.json";
		$maxCacheAge = 86400;
		if (
			isset($this->chatBot->vars["my_guild_id"])
			&& $this->chatBot->vars["my_guild_id"] === $guildID
		) {
			$maxCacheAge = 21600;
		}
		$cb = function($data) {
			try {
				if ($data === null) {
					return false;
				}
				$result = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
				return $result !== null;
			} catch (JsonException $e) {
				return false;
			}
		};

		$cacheResult = $this->cacheManager->lookup($url, $groupName, $filename, $cb, $maxCacheAge, $forceUpdate);

		// if there is still no valid data available give an error back
		if ($cacheResult->success !== true) {
			return null;
		}
		
		[$orgInfo, $members, $lastUpdated] = json_decode($cacheResult->data);
		
		$guild = new Guild();
		$guild->guild_id = $guildID;

		// parsing of the member data
		$guild->orgname	= $orgInfo->NAME;
		$guild->orgside	= $orgInfo->SIDE_NAME;

		// pre-fetch the charids...this speeds things up immensely
		foreach ($members as $member) {
			$name = $member->NAME;
			if (!isset($this->chatBot->id[$name])) {
				$this->chatBot->sendPacket(
					new AOChatPacket("out", AOCP_CLIENT_LOOKUP, $name)
				);
			}
		}

		foreach ($members as $member) {
			$name = $member->NAME;
			$charid = $this->chatBot->get_uid($name);
			if ($charid === null || $charid === false) {
				$charid = 0;
			}

			$guild->members[$name]                 = new Player();
			$guild->members[$name]->charid         = $charid;
			$guild->members[$name]->firstname      = trim($member->FIRSTNAME);
			$guild->members[$name]->name           = $name;
			$guild->members[$name]->lastname       = trim($member->LASTNAME);
			$guild->members[$name]->level          = $member->LEVELX;
			$guild->members[$name]->breed          = $member->BREED;
			$guild->members[$name]->gender         = $member->SEX;
			$guild->members[$name]->faction        = $guild->orgside;
			$guild->members[$name]->profession     = $member->PROF;
			$guild->members[$name]->prof_title     = $member->PROF_TITLE;
			$guild->members[$name]->ai_rank        = $member->DEFENDER_RANK_TITLE;
			$guild->members[$name]->ai_level       = $member->ALIENLEVEL;
			$guild->members[$name]->guild_id       = $guild->guild_id;
			$guild->members[$name]->guild          = $guild->orgname;
			$guild->members[$name]->guild_rank     = $member->RANK_TITLE;
			$guild->members[$name]->guild_rank_id  = $member->RANK;
			$guild->members[$name]->dimension      = $dimension;
			$guild->members[$name]->source         = 'org_roster';
			
			$guild->members[$name]->head_id        = $member->HEADID;
			$guild->members[$name]->pvp_rating     = $member->PVPRATING;
			$guild->members[$name]->pvp_title      = $member->PVPTITLE;
		}

		// this is done separately from the loop above to prevent nested transaction errors from occurring
		// when looking up charids for characters
		if ($cacheResult->usedCache === false) {
			$this->db->beginTransaction();

			$sql = "UPDATE players SET guild_id = 0, guild = '' WHERE guild_id = ? AND dimension = ?";
			$this->db->exec($sql, $guild->guild_id, $dimension);

			foreach ($guild->members as $member) {
				$this->playerManager->update($member);
			}

			$this->db->commit();
		}

		return $guild;
	}
}
