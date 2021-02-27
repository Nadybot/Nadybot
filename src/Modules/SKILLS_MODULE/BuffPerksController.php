<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	LoggerWrapper,
	Text,
	Util,
	Modules\PLAYER_LOOKUP\PlayerManager,
	SettingManager,
};
use Nadybot\Core\DBSchema\Player;
use Nadybot\Modules\ITEMS_MODULE\WhatBuffsController;

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
	public WhatBuffsController $whatBuffsController;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$msg = $this->db->loadSQLFile($this->moduleName, "perks");
		$path = __DIR__ . "/perks.csv";
		$mtime = @filemtime($path);
		$dbVersion = 0;
		if ($this->settingManager->exists("perks_db_version")) {
			$dbVersion = (int)$this->settingManager->get("perks_db_version");
		}
		if ( ($mtime === false || $dbVersion >= $mtime)
			&& preg_match("/database already up to date/", $msg)) {
			// return;
		}

		$perkInfo = $this->getPerkInfo();

		$this->db->exec("DELETE FROM perk");
		$this->db->exec("DELETE FROM perk_level");
		$this->db->exec("DELETE FROM perk_level_prof");
		$this->db->exec("DELETE FROM perk_level_buffs");
		$this->db->exec("DELETE FROM perk_level_actions");
		$this->db->exec("DELETE FROM perk_level_resistances");

		foreach ($perkInfo as $perk) {
			$perk->id = $this->db->insert("perk", $perk);

			foreach ($perk->levels as $level) {
				$level->perk_id = $perk->id;
				$level->id = $this->db->insert('perk_level', $level);

				foreach ($level->professions as $profession) {
					$this->db->exec("INSERT INTO perk_level_prof (perk_level_id, profession) VALUES (?, ?)", $level->id, $profession);
				}

				foreach ($level->resistances as $strain => $amount) {
					$this->db->exec(
						"INSERT INTO perk_level_resistances (perk_level_id, strain_id, amount) VALUES (?, ?, ?)",
						$level->id,
						(int)$strain,
						(int)$amount
					);
				}

				if ($level->action) {
					$this->db->exec(
						"INSERT INTO perk_level_actions (perk_level_id, action_id) VALUES (?, ?)",
						$level->id,
						(int)$level->action
					);
				}

				foreach ($level->buffs as $skillId => $amount) {
					$this->db->exec(
						"INSERT INTO `perk_level_buffs` (`perk_level_id`, `skill_id`, `amount`) ".
						"VALUES (?, ?, ?)",
						$level->id,
						(int)$skillId,
						$amount
					);
				}
			}
		}
		$newVersion = max($mtime ?: time(), $dbVersion);
		$this->settingManager->save("perks_db_version", (string)$newVersion);
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
			$this->playerManager->getByNameAsync(
				function(?Player $whois) use ($args, $sendto): void {
					if (empty($whois)) {
						$msg = "Could not retrieve whois info for you.";
						$sendto->reply($msg);
						return;
					}
					$this->showPerks($whois->profession, $whois->level, null, $sendto);
				},
				$sender
			);
			return;
		}
		if (($first = $this->util->getProfessionName($args[1])) !== '') {
			$profession = $first;
			$minLevel = (int)$args[2];
		} elseif (($second = $this->util->getProfessionName($args[2])) !== '') {
			$profession = $second;
			$minLevel = (int)$args[1];
		} else {
			$msg = "Could not find profession <highlight>$args[1]<end> or <highlight>$args[2]<end>.";
			$sendto->reply($msg);
			return;
		}
		$this->showPerks($profession, $minLevel, $args[3] ?? null, $sendto);
	}

	protected function showPerks(string $profession, int $minLevel, string $search=null, CommandReply $sendto): void {
		$params =  [$profession, $minLevel];

		$skillQuery = "";
		if ($search !== null) {
			$skills = $this->whatBuffsController->searchForSkill($search);
			if (count($skills) === 0) {
				$sendto->reply("No skill <highlight>{$search}<end> found.");
				return;
			}
			if (count($skills) > 1) {
				$sendto->reply("No clear match for search term <highlight>{$search}<end>.");
				return;
			}
			$params = [...$params, $skills[0]->id];
			$skillQuery = "AND plb.skill_id=?";
		}

		$sql = "SELECT p.name AS `perk_name`, ".
				"MAX(pl.`perk_level`) AS `max_perk_level`, ".
				"0 AS `class`, ".
				"SUM(plb.`amount`) AS `buff_amount`, ".
				"p.`expansion` AS `expansion`, ".
				"s.name AS `skill`, ".
				"s.unit AS `unit`, ".
				"(SELECT COUNT(*) FROM `perk_level_prof` plp WHERE plp.perk_level_id=pl.id) AS num_profs ".
			"FROM ".
				"perk_level_prof plp ".
				"JOIN perk_level pl ON plp.perk_level_id = pl.id ".
				"JOIN perk_level_buffs plb ON pl.id = plb.perk_level_id ".
				"JOIN perk p ON pl.perk_id = p.id ".
				"JOIN skills s ON plb.skill_id = s.id ".
			"WHERE ".
				"plp.profession = ? ".
				"AND pl.required_level <= ? ".
				"$skillQuery ".
			"GROUP BY ".
				"p.name, ".
				"plb.skill_id ".
			"ORDER BY ".
				"p.name";

		$data = $this->db->query($sql, ...$params);

		if (empty($data)) {
			$msg = "Could not find any perks for level $minLevel $profession.";
			$sendto->reply($msg);
			return;
		}
		if ($search === null) {
			$sql = "SELECT p.name AS `perk_name`, ".
					"p.`expansion` AS `expansion`, ".
					"1 AS `class`, ".
					"nl.`name` AS `skill`, ".
					"'' AS `unit`, ".
					"MAX(pl.`perk_level`) AS `max_perk_level`, ".
					"SUM(plr.`amount`) AS `buff_amount`, ".
					"plr.`strain_id`, ".
					"(SELECT COUNT(*) FROM `perk_level_prof` plp WHERE plp.perk_level_id=pl.id) AS `num_profs` ".
				"FROM ".
					"`perk_level_prof` plp ".
					"JOIN `perk_level` pl ON plp.`perk_level_id` = pl.`id` ".
					"JOIN `perk_level_resistances` plr ON pl.`id` = plr.`perk_level_id` ".
					"JOIN `perk` p ON pl.`perk_id` = p.`id` ".
					"JOIN `nano_lines` nl ON plr.`strain_id` = nl.`strain_id` ".
				"WHERE ".
					"plp.`profession` = ? ".
					"AND pl.`required_level` <= ? ".
				"GROUP BY ".
					"p.`name`, ".
					"plr.`strain_id` ".
				"ORDER BY ".
					"p.`name`";

			$data2 = $this->db->query($sql, $profession, $minLevel);
			$data = [...$data, ...$data2];

			$sql = "SELECT p.name AS `perk_name`, ".
					"p.`expansion` AS `expansion`, ".
					"2 AS `class`, ".
					"a.`name` AS `skill`, ".
					"a.`lowql` AS `buff_amount`, ".
					"a.`lowid`, ".
					"a.`highid`, ".
					"'' AS `unit`, ".
					"MAX(pl.`perk_level`) AS `max_perk_level`, ".
					"(SELECT COUNT(*) FROM `perk_level_prof` plp WHERE plp.perk_level_id=pl.id) AS `num_profs` ".
				"FROM ".
					"`perk_level_prof` plp ".
					"JOIN `perk_level` pl ON plp.`perk_level_id` = pl.`id` ".
					"JOIN `perk_level_actions` pla ON pl.`id` = pla.`perk_level_id` ".
					"JOIN `perk` p ON pl.`perk_id` = p.`id` ".
					"JOIN `aodb` a ON pla.`action_id` = a.`lowid` ".
				"WHERE ".
					"plp.`profession` = ? ".
					"AND pl.`required_level` <= ? ".
				"GROUP BY ".
					"p.`name`, ".
					"pla.`action_id` ".
				"ORDER BY ".
					"p.`name`";

			$data2 = $this->db->query($sql, $profession, $minLevel);
			$data = [...$data, ...$data2];
		}
		usort(
			$data,
			function(object $o1, object $o2): int {
				$o1->type = $o1->num_profs == 14 ? 2 : ($o1->num_profs == 1 ? 0 : 1);
				$o2->type = $o2->num_profs == 14 ? 2 : ($o2->num_profs == 1 ? 0 : 1);
				return $o1->type <=> $o2->type
					?: strcmp($o1->perk_name, $o2->perk_name)
					?: $o1->class <=> $o2->class
					?: (($o1->class == 2)
						? $o1->max_perk_level <=> $o2->max_perk_level
						: strcmp($o1->skill, $o2->skill));
			}
		);
		$currentPerk = '';
		$blob = '';
		$totalBuffSL = 0;
		$totalBuffUnit = 0;
		$totalBuffAI = 0;
		$lastType = null;
		foreach ($data as $row) {
			if ($row->type !== $lastType) {
				$type = $row->type == 0
					? "Profession Perks"
					: ($row->type == 1 ? "Group Perks" : "General Perks");
				$blob .= "\n<pagebreak><header2>{$type}<end>\n";
				$lastType = $row->type;
			}
			if ($row->perk_name !== $currentPerk) {
				$color = ($row->expansion === "ai" ? "<green>" : "<font color=#FF6666>");
				$blob .= "\n<tab>{$color}{$row->perk_name} {$row->max_perk_level}<end>\n";
				$currentPerk = $row->perk_name;
			}

			if ($row->class == 0) {
				$blob .= sprintf(
					"<tab><tab>%s <highlight>%+d%s<end>\n",
					$row->skill,
					$row->buff_amount,
					$row->unit
				);
			} elseif ($row->class == 1) {
				$blob .= sprintf(
					"<tab><tab>Resist %s <highlight>%+d%s<end>\n",
					$row->skill,
					$row->buff_amount,
					$row->unit
				);
			} else {
				$blob .= sprintf(
					"<tab><tab>Add Action at %s: %s\n",
					$this->text->alignNumber($row->max_perk_level, 2),
					$this->text->makeItem($row->lowid, $row->highid, $row->buff_amount, $row->skill),
				);
			}
			if ($search !== null) {
				$totalBuffUnit = $row->unit;
				if ($row->expansion === "ai") {
					$totalBuffAI += $row->buff_amount;
				} else {
					$totalBuffSL += $row->buff_amount;
				}
			}
		}
		if ($search !== null) {
			$blob .= sprintf(
				"\n<header2>Total Buff<end>\n<tab>%s: <%s>%+d%s<end>",
				$skills[0]->name,
				$totalBuffAI > 0 ? "green" : "highlight",
				$totalBuffAI + $totalBuffSL,
				$totalBuffUnit
			);
			if ($totalBuffAI > 0) {
				$blob .= sprintf(
					" (<highlight>%+d%s<end>)",
					$totalBuffSL,
					$totalBuffUnit
				);
			}
		}
		$blob .= "\n<a href='itemref://252496/252496/1'>Atrox Primary Genome 5</a>";

		$msg = $this->text->makeBlob("Buff Perks for $minLevel $profession", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Expand a skill name into a list of skills, supporting aliases like AC, Reflect, etc.
	 *
	 * @return string[]
	 */
	protected function expandSkill(string $skill): array {
		if ($skill === "Add. Dmg.") {
			return [
				"Add. Cold Dam.",
				"Add. Chem Dam.",
				"Add. Energy Dam.",
				"Add. Fire Dam.",
				"Add. Melee Dam.",
				"Add. Poison Dam.",
				"Add. Rad. Dam.",
				"Add. Proj. Dam."
			];
		} elseif ($skill === "AC") {
			return [
				"Melee/ma AC",
				"Disease AC",
				"Fire AC",
				"Cold AC",
				"Imp/Proj AC",
				"Energy AC",
				"Chemical AC",
				"Radiation AC"
			];
		} elseif ($skill === "Shield") {
			return [
				"ShieldProjectileAC",
				"ShieldMeleeAC",
				"ShieldEnergyAC",
				"ShieldChemicalAC",
				"ShieldRadiationAC",
				"ShieldColdAC",
				"ShieldNanoAC",
				"ShieldFireAC",
				"ShieldPoisonAC",
			];
		} elseif ($skill === "Reflect") {
			return [
				"ReflectProjectileAC",
				"ReflectMeleeAC",
				"ReflectEnergyAC",
				"ReflectChemicalAC",
				"ReflectRadiationAC",
				"ReflectColdAC",
				"ReflectNanoAC",
				"ReflectFireAC",
				"ReflectPoisonAC",
			];
		}
		return [$skill];
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

			$parts = explode("|", $line);
			$aoid = null;
			$expansion = "sl";
			if (count($parts) < 7) {
				$this->logger->log("ERROR", "Illegal perk entry: {$line}");
				continue;
			}
			[$name, $perkLevel, $expansion, $aoid, $requiredLevel, $profs, $buffs] = $parts;
			$action = $parts[7] ?? null;
			$resistances = $parts[8] ?? null;
			if ($profs === '*') {
				$profs = "Adv, Agent, Crat, Doc, Enf, Engi, Fix, Keep, MA, MP, NT, Shade, Sol, Tra";
			}
			$perk = $perks[$name];
			if (empty($perk)) {
				$perk = new Perk();
				$perks[$name] = $perk;
				$perk->name = $name;
				$perk->expansion = $expansion;
			}

			$level = new PerkLevel();
			$perk->levels[$perkLevel] = $level;

			$level->perk_level = (int)$perkLevel;
			$level->required_level = (int)$requiredLevel;
			$level->aoid = isset($aoid) ? (int)$aoid : null;

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
				if ($pos === false) {
					continue;
				}

				$skill = trim(substr($buff, 0, $pos + 1));
				$amount = trim(substr($buff, $pos + 1));
				$skills = $this->expandSkill($skill);
				foreach ($skills as $skill) {
					$skillSearch = $this->whatBuffsController->searchForSkill($skill);
					if (count($skillSearch) !== 1) {
						echo "Error parsing skill: '{$skill}'\n";
					} else {
						$level->buffs[$skillSearch[0]->id] = (int)$amount;
					}
				}
			}

			if (strlen($resistances??'')) {
				$resistances = preg_split("/\s*,\s*/", $resistances);
				foreach ($resistances as $resistance) {
					[$strainId, $amount] = preg_split("/\s*:\s*/", $resistance);
					$level->resistances[$strainId] = (int)$amount;
				}
			}
			if (strlen($action??'')) {
				$level->action = (int)$action;
			}
		}
		return $perks;
	}
}
