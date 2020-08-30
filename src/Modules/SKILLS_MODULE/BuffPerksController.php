<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
	Util,
	Modules\PLAYER_LOOKUP\PlayerManager,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'perks',
 *		accessLevel = 'all',
 *		description = 'Show buff perks',
 *		help        = 'perks.txt'
 *	)
 */
class BuffPerksController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;
	
	/**
	 * @var \Nadybot\Core\Text $text
	 * @Inject
	 */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public PlayerManager $playerManager;
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "perks");
		
		$perkInfo = $this->getPerkInfo();
		
		$this->db->exec("DELETE FROM perk");
		$this->db->exec("DELETE FROM perk_level");
		$this->db->exec("DELETE FROM perk_level_prof");
		$this->db->exec("DELETE FROM perk_level_buffs");

		$perkId = 1;
		$perkLevelId = 1;
		foreach ($perkInfo as $perk) {
			$this->db->exec("INSERT INTO perk (id, name) VALUES (?, ?)", $perkId, $perk->name);
			
			foreach ($perk->levels as $level) {
				$this->db->exec("INSERT INTO perk_level (id, perk_id, number, min_level) VALUES (?, ?, ?, ?)", $perkLevelId, $perkId, $level->number, $level->min_level);
				
				foreach ($level->professions as $profession) {
					$this->db->exec("INSERT INTO perk_level_prof (perk_level_id, profession) VALUES (?, ?)", $perkLevelId, $profession);
				}

				foreach ($level->buffs as $buff => $amount) {
					$this->db->exec("INSERT INTO perk_level_buffs (perk_level_id, skill, amount) VALUES (?, ?, ?)", $perkLevelId, $buff, $amount);
				}
				
				$perkLevelId++;
			}

			$perkId++;
		}
	}
	
	/**
	 * @HandlesCommand("perks")
	 * @Matches("/^perks$/i")
	 * @Matches("/^perks ([a-z-]*) (\d+)$/i")
	 * @Matches("/^perks ([a-z-]*) (\d+) (.*)$/i")
	 * @Matches("/^perks (\d+) ([a-z-]*)$/i")
	 * @Matches("/^perks (\d+) ([a-z-]*) (.*)$/i")
	 */
	public function buffPerksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (count($args) === 1) {
			$whois = $this->playerManager->getByName($sender);
			if (empty($whois)) {
				$msg = "Could not retrieve whois info for you.";
				$sendto->reply($msg);
				return;
			} else {
				$profession = $whois->profession;
				$minLevel = $whois->level;
			}
		} else {
			if (($first = $this->util->getProfessionName($args[1])) !== '') {
				$profession = $first;
				$minLevel = $args[2];
			} elseif (($second = $this->util->getProfessionName($args[2])) !== '') {
				$profession = $second;
				$minLevel = $args[1];
			} else {
				$msg = "Could not find profession <highlight>$args[1]<end> or <highlight>$args[2]<end>.";
				$sendto->reply($msg);
				return;
			}
		}
		
		$params =  [$profession, $minLevel];
		
		if (count($args) === 4) {
			$tmp = explode(" ", $args[3]);
			[$skillQuery, $newParams] = $this->util->generateQueryFromParams($tmp, 'plb.skill');
			$params = [...$params, ...$newParams];
			$skillQuery = "AND " . $skillQuery;
		}
		
		$sql = "SELECT p.name AS perk_name, ".
				"MAX(pl.number) AS max_perk_level, ".
				"SUM(plb.amount) AS buff_amount, ".
				"plb.skill ".
			"FROM ".
				"perk_level_prof plp ".
				"JOIN perk_level pl ON plp.perk_level_id = pl.id ".
				"JOIN perk_level_buffs plb ON pl.id = plb.perk_level_id ".
				"JOIN perk p ON pl.perk_id = p.id ".
			"WHERE ".
				"plp.profession = ? ".
				"AND pl.min_level <= ? ".
				"$skillQuery ".
			"GROUP BY ".
				"p.name, ".
				"plb.skill ".
			"ORDER BY ".
				"p.name";

		$data = $this->db->query($sql, ...$params);
		
		if (empty($data)) {
			$msg = "Could not find any perks for level $minLevel $profession.";
			$sendto->reply($msg);
			return;
		}
		$currentPerk = '';
		$blob = '';
		foreach ($data as $row) {
			if ($row->perk_name !== $currentPerk) {
				$blob .= "\n<header2>$row->perk_name {$row->max_perk_level}<end>\n";
				$currentPerk = $row->perk_name;
			}
			
			$blob .= "<tab>$row->skill <highlight>$row->buff_amount<end>\n";
		}
		
		$msg = $this->text->makeBlob("Buff Perks for $minLevel $profession", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @return array<string,Perk>
	 */
	public function getPerkInfo(): array {
		$path = __DIR__ . "/perks.csv";
		$lines = explode("\n", file_get_contents($path));
		$perks = [];
		foreach ($lines as $line) {
			$line = trim($line);
			
			if (empty($line)) {
				continue;
			}
			
			[$name, $perkLevel, $minLevel, $profs, $buffs] = explode("|", $line);
			$perk = $perks[$name];
			if (empty($perk)) {
				$perk = new Perk();
				$perks[$name] = $perk;
				$perk->name = $name;
			}
			
			$level = new PerkLevel();
			$perk->levels[$perkLevel] = $level;

			$level->number = (int)$perkLevel;
			$level->min_level = (int)$minLevel;
			
			$professions = explode(",", $profs);
			foreach ($professions as $prof) {
				$profession = $this->util->getProfessionName(trim($prof));
				if (empty($profession)) {
					echo "Error parsing profession: '$prof'\n";
				} else {
					$level->professions []= $profession;
				}
			}
			
			$buffs = explode(",", $buffs);
			foreach ($buffs as $buff) {
				$buff = trim($buff);
				$pos = strrpos($buff, " ");

				$skill = substr($buff, 0, $pos + 1);
				$amount = substr($buff, $pos + 1);

				$level->buffs[$skill] = (int)$amount;
			}
		}
		
		return $perks;
	}
}
