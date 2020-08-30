<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\{
	DB,
	Http,
	Nadybot,
	SQLException,
	Util,
};
use Nadybot\Core\DBSchema\Player;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class PlayerManager {
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public Http $http;

	public function getByName(string $name, int $dimension=null, bool $forceUpdate=false): ?Player {
		$dimension ??= (int)$this->chatBot->vars['dimension'];

		$name = ucfirst(strtolower($name));

		$charid = '';
		if ($dimension === (int)$this->chatBot->vars['dimension']) {
			$charid = $this->chatBot->get_uid($name);
		}

		$player = $this->findInDb($name, $dimension);

		if ($player === null || $forceUpdate) {
			$player = $this->lookup($name, $dimension);
			if ($player !== null && $charid !== false) {
				$player->charid = $charid;
				$this->update($player);
			}
		} elseif ($player->last_update < (time() - 86400)) {
			$player2 = $this->lookup($name, $dimension);
			if ($player2 !== null) {
				$player = $player2;
				if ($charid !== false) {
					$player->charid = $charid;
					$this->update($player);
				}
			} else {
				$player->source .= ' (old-cache)';
			}
		} else {
			$player->source .= ' (current-cache)';
		}

		return $player;
	}

	public function findInDb(string $name, int $dimension): ?Player {
		$sql = "SELECT * FROM players WHERE name LIKE ? AND dimension = ? LIMIT 1";
		return $this->db->fetch(Player::class, $sql, $name, $dimension);
	}

	public function lookup(string $name, int $dimension): ?Player {
		$obj = $this->lookupUrl("http://people.anarchy-online.com/character/bio/d/$dimension/name/$name/bio.xml?data_type=json");
		if (isset($obj) && $obj->name === $name) {
			$obj->source = 'people.anarchy-online.com';
			$obj->dimension = $dimension;
			return $obj;
		}

		return null;
	}

	private function lookupUrl(string $url): ?Player {
		$response = $this->http->get($url)->waitAndReturnResponse();
		if (!isset($response) || $response->headers["status-code"] !== "200") {
			return null;
		}
		if ($response->body === "null") {
			return null;
		}
		[$char, $org] = json_decode($response->body);

		$obj = new Player();

		// parsing of the player data
		$obj->firstname      = trim($char->FIRSTNAME);
		$obj->name           = $char->NAME;
		$obj->lastname       = trim($char->LASTNAME);
		$obj->level          = $char->LEVELX;
		$obj->breed          = $char->BREED;
		$obj->gender         = $char->SEX;
		$obj->faction        = $char->SIDE;
		$obj->profession     = $char->PROF;
		$obj->prof_title     = $char->PROFNAME;
		$obj->ai_rank        = $char->RANK_name;
		$obj->ai_level       = $char->ALIENLEVEL;
		$obj->guild_id       = $org->ORG_INSTANCE;
		$obj->guild          = $org->NAME ?? '';
		$obj->guild_rank     = $org->RANK_TITLE ?? '';
		$obj->guild_rank_id  = $org->RANK;

		$obj->head_id        = $char->HEADID;
		$obj->pvp_rating     = $char->PVPRATING;
		$obj->pvp_title      = $char->PVPTITLE;

		//$obj->charid        = $char->CHAR_INSTANCE;
		$obj->dimension      = $char->CHAR_DIMENSION;

		return $obj;
	}

	public function update(Player $char): void {
		$sql = "DELETE FROM players WHERE `name` = ? AND `dimension` = ?";
		$this->db->exec($sql, $char->name, $char->dimension);
		
		$char->guild_id ??= 0;
		
		if ($char->guild_rank_id === '') {
			$char->guild_rank_id = -1;
		}

		$sql = "
			INSERT INTO players (
				`charid`,
				`firstname`,
				`name`,
				`lastname`,
				`level`,
				`breed`,
				`gender`,
				`faction`,
				`profession`,
				`prof_title`,
				`ai_rank`,
				`ai_level`,
				`guild_id`,
				`guild`,
				`guild_rank`,
				`guild_rank_id`,
				`dimension`,
				`head_id`,
				`pvp_rating`,
				`pvp_title`,
				`source`,
				`last_update`
			) VALUES (
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?,
				?
			)";

		$this->db->exec(
			$sql,
			$char->charid,
			$char->firstname,
			$char->name,
			$char->lastname,
			$char->level,
			$char->breed,
			$char->gender,
			$char->faction,
			$char->profession,
			$char->prof_title,
			$char->ai_rank,
			$char->ai_level,
			$char->guild_id,
			$char->guild,
			$char->guild_rank,
			$char->guild_rank_id,
			$char->dimension,
			$char->head_id,
			$char->pvp_rating,
			$char->pvp_title,
			$char->source,
			time()
		);
	}

	public function getInfo(Player $whois, bool $showFirstAndLastName=true): string {
		$msg = '';

		if ($showFirstAndLastName && strlen($whois->firstname??"")) {
			$msg = $whois->firstname . " ";
		}

		$msg .= "<highlight>\"{$whois->name}\"<end> ";

		if ($showFirstAndLastName && strlen($whois->lastname??"")) {
			$msg .= $whois->lastname . " ";
		}

		$msg .= "(<highlight>{$whois->level}<end>/<green>{$whois->ai_level}<end>";
		$msg .= ", {$whois->gender} {$whois->breed} <highlight>{$whois->profession}<end>";
		$msg .= ", <" . strtolower($whois->faction) . ">$whois->faction<end>";

		if ($whois->guild) {
			$msg .= ", {$whois->guild_rank} of <" . strtolower($whois->faction) . ">{$whois->guild}<end>)";
		} else {
			$msg .= ", Not in a guild)";
		}

		return $msg;
	}
	
	/**
	 * Search for players in the database
	 *
	 * @param string $search Search term
	 * @param int|null $dimension Dimension to limit search to
	 * @return array Player[]
	 * @throws SQLException On error
	 */
	public function searchForPlayers(string $search, ?int $dimension=null): array {
		$searchTerms = explode(' ', $search);
		[$query, $params] = $this->util->generateQueryFromParams($searchTerms, 'name');

		if ($dimension === null) {
			$sql = "SELECT * FROM players WHERE $query ORDER BY name ASC LIMIT 100";
			return $this->db->fetchAll(Player::class, $sql, $params);
		}
		$sql = "SELECT * FROM players WHERE $query AND dimension = ? ORDER BY name ASC LIMIT 100";
		$params []= $dimension;

		return $this->db->fetchAll(Player::class, $sql, ...$params);
	}
}
