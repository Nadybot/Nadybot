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
			return;
		}

		$perkInfo = $this->getPerkInfo();

		$this->db->exec("DELETE FROM perk");
		$this->db->exec("DELETE FROM perk_level");
		$this->db->exec("DELETE FROM perk_level_prof");
		$this->db->exec("DELETE FROM perk_level_buffs");

		foreach ($perkInfo as $perk) {
			$perk->id = $this->db->insert("perk", $perk);

			foreach ($perk->levels as $level) {
				$level->perk_id = $perk->id;
				$level->id = $this->db->insert('perk_level', $level);

				foreach ($level->professions as $profession) {
					$this->db->exec("INSERT INTO perk_level_prof (perk_level_id, profession) VALUES (?, ?)", $level->id, $profession);
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
				"SUM(plb.`amount`) AS `buff_amount`, ".
				"p.`expansion` AS `expansion`, ".
				"s.name AS `skill`, ".
				"s.unit AS `unit` ".
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
		$currentPerk = '';
		$blob = '';
		$totalBuffSL = 0;
		$totalBuffUnit = 0;
		$totalBuffAI = 0;
		foreach ($data as $row) {
			if ($row->perk_name !== $currentPerk) {
				$blob .= "\n<header2>$row->perk_name {$row->max_perk_level}".
					($row->expansion === "ai" ? " (<green>AI<end>)" : "").
					"<end>\n";
				$currentPerk = $row->perk_name;
			}

			$blob .= sprintf(
				"<tab>%s <highlight>%+d%s<end>\n",
				$row->skill,
				$row->buff_amount,
				$row->unit
			);
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
			if (count($parts) === 7) {
				[$name, $perkLevel, $expansion, $aoid, $requiredLevel, $profs, $buffs] = $parts;
			} else {
				$this->logger->log("ERROR", "Illegal perk entry: {$line}");
				continue;
			}
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
		}

		return $perks;
	}
}
