<?php

namespace Budabot\Core\Modules\PLAYER_LOOKUP;

use stdClass;
use Budabot\Core\AOChatPacket;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class GuildManager {
	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;
	
	/**
	 * @var \Budabot\Core\CacheManager $cacheManager
	 * @Inject
	 */
	public $cacheManager;
	
	/**
	 * @var \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerManager $playerManager
	 * @Inject
	 */
	public $playerManager;

	public function getById($guild_id, $rk_num=0, $forceUpdate=false) {
		// if no server number is specified use the one on which the bot is logged in
		if ($rk_num == 0) {
			$rk_num = $this->chatBot->vars["dimension"];
		}
		
		$url = "http://people.anarchy-online.com/org/stats/d/$rk_num/name/$guild_id/basicstats.xml?data_type=json";
		$groupName = "guild_roster";
		$filename = "$guild_id.$rk_num.json";
		if ($this->chatBot->vars["my_guild_id"] == $guild_id) {
			$maxCacheAge = 21600;
		} else {
			$maxCacheAge = 86400;
		}
		$cb = function($data) {
			$result = json_decode($data) != null;
			return $result;
		};

		$cacheResult = $this->cacheManager->lookup($url, $groupName, $filename, $cb, $maxCacheAge, $forceUpdate);

		// if there is still no valid data available give an error back
		if ($cacheResult->success !== true) {
			return null;
		}
		
		list($orgInfo, $members, $lastUpdated) = json_decode($cacheResult->data);
		
		$guild = new stdClass;
		$guild->guild_id = $guild_id;

		// parsing of the member data
		$guild->orgname	= $orgInfo->NAME;
		$guild->orgside	= $orgInfo->SIDE_NAME;

		// pre-fetch the charids...this speeds things up immensely
		foreach ($members as $member) {
			$name = $member->NAME;
			if (!isset($this->chatBot->id[$name])) {
				$this->chatBot->sendPacket(new AOChatPacket("out", AOCP_CLIENT_LOOKUP, $name));
			}
		}

		foreach ($members as $member) {
			$name = $member->NAME;
			$charid = $this->chatBot->get_uid($name);
			if ($charid == null) {
				$charid = 0;
			}

			$guild->members[$name]                 = new stdClass;
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
			$guild->members[$name]->dimension      = $rk_num;
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
			$this->db->exec($sql, $guild->guild_id, $rk_num);

			foreach ($guild->members as $member) {
				$this->playerManager->update($member);
			}

			$this->db->commit();
		}

		return $guild;
	}
}
