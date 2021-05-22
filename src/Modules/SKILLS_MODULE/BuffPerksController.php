<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	LoggerWrapper,
	Text,
	Util,
	Modules\PLAYER_LOOKUP\PlayerManager,
	DBSchema\Player,
	SettingManager,
	Timer,
};

use Nadybot\Modules\{
	ITEMS_MODULE\AODBEntry,
	ITEMS_MODULE\Skill,
	ITEMS_MODULE\WhatBuffsController,
};
use Throwable;

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
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
	public const ALIEN_INVASION = "ai";
	public const SHADOWLANDS = "sl";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/** @Inject */
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

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$applied = $this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Perks");
		$this->settingManager->add($this->moduleName, "perks_db_version", "perks_db_version", 'noedit', 'text', "0");
		$this->timer->callLater(0, [$this, "initPerksDatabase"], $applied);
	}

	public function initPerksDatabase(bool $applied): void {
		if ($this->db->inTransaction()) {
			$this->timer->callLater(0, [$this, "initPerksDatabase"], $applied);
			return;
		}
		$path = __DIR__ . "/perks.csv";
		$mtime = @filemtime($path);
		$dbVersion = 0;
		if ($this->settingManager->exists("perks_db_version")) {
			$dbVersion = (int)$this->settingManager->get("perks_db_version");
		}
		if (($mtime === false || $dbVersion >= $mtime) && !$applied) {
			return;
		}
		$this->logger->log("INFO", "(Re)building perk database...");

		$perkInfo = $this->getPerkInfo();

		$this->db->beginTransaction();
		try {
			$this->db->table("perk")->truncate();
			$this->db->table("perk_level")->truncate();
			$this->db->table("perk_level_prof")->truncate();
			$this->db->table("perk_level_buffs")->truncate();
			$this->db->table("perk_level_actions")->truncate();
			$this->db->table("perk_level_resistances")->truncate();

			$profInserts = [];
			$resInserts = [];
			$buffInserts = [];
			foreach ($perkInfo as $perk) {
				$perk->id = $this->db->insert("perk", $perk);

				foreach ($perk->levels as $level) {
					$level->perk_id = $perk->id;
					$level->id = $this->db->insert('perk_level', $level);

					foreach ($level->professions as $profession) {
						$profInserts []= [
							"perk_level_id" => $level->id,
							"profession" => $profession,
						];
					}

					foreach ($level->resistances as $strain => $amount) {
						$resInserts []= [
							"perk_level_id" => $level->id,
							"strain_id" => (int)$strain,
							"amount" => (int)$amount,
						];
					}

					if ($level->action) {
						$level->action->perk_level_id = $level->id;
						$this->db->insert("perk_level_actions", $level->action);
					}

					foreach ($level->buffs as $skillId => $amount) {
						$buffInserts []= [
							"perk_level_id" => $level->id,
							"skill_id" => (int)$skillId,
							"amount" => (int)$amount,
						];
					}
				}
			}
			foreach (array_chunk($profInserts, 800, false) as $inserts) {
				$this->db->table("perk_level_prof")->insert($inserts);
			}
			foreach (array_chunk($resInserts, 800, false) as $inserts) {
				$this->db->table("perk_level_resistances")->insert($inserts);
			}
			foreach (array_chunk($buffInserts, 800, false) as $inserts) {
				$this->db->table("perk_level_buffs")->insert($inserts);
			}
			$newVersion = max($mtime ?: time(), $dbVersion);
			$this->settingManager->save("perks_db_version", (string)$newVersion);
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
		$this->db->commit();
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
					$this->showPerks($whois->profession, $whois->level, $whois->breed, null, $sendto);
				},
				$sender
			);
			return;
		}
		if (($first = $this->util->getProfessionName($args[1])) !== '') {
			$profession = $first;
			$level = (int)$args[2];
		} elseif (($second = $this->util->getProfessionName($args[2])) !== '') {
			$profession = $second;
			$level = (int)$args[1];
		} else {
			$msg = "Could not find profession <highlight>$args[1]<end> or <highlight>$args[2]<end>.";
			$sendto->reply($msg);
			return;
		}
		$this->showPerks($profession, $level, null, $args[3] ?? null, $sendto);
	}

	/**
	 * Filter a perk list $perks to only show breed-specific perks for $breed
	 *
	 * @param Perk[] $perks
	 * @param string $breed
	 * @return Perk[]
	 */
	protected function filterBreedPerks(array $perks, string $breed): array {
		$result = [];
		foreach ($perks as $perk) {
			if (
				preg_match("/(Primary|Secondary) Genome/", $perk->name)
				&& !preg_match("/^$breed/", $perk->name)
			) {
				continue;
			}
			$result []= $perk;
		}
		return $result;
	}

	/**
	 * Filter a perk list $perks to only show those buffing $skill
	 *
	 * @param Perk[] $perks
	 * @param Skill $skill
	 * @return Perk[]
	 */
	protected function filterPerkBuff(array $perks, Skill $skill): array {
		// Filter out all perks that don't buff anything in $skill
		/** @var Perk[] */
		$result = array_values(array_filter(
			$perks,
			function(Perk $perk) use ($skill): bool {
				// Delete all buffs except for the searched skill
				foreach ($perk->levels as $level) {
					$buffs = [];
					$level->perk_resistances = [];
					$level->action = null;
					foreach ($level->perk_buffs as $buff) {
						if ($buff->skill_id === $skill->id && $buff->amount > 0) {
							$buffs []= $buff;
						}
					}
					$level->perk_buffs = $buffs;
				}
				// Completely delete all perk levels not buffing the searched skill
				$perk->levels = array_filter(
					$perk->levels,
					function(PerkLevel $level): bool {
						return count($level->perk_buffs) > 0;
					}
				);
				return count($perk->levels) > 0;
			}
		));
		return $result;
	}

	/**
	 * Show all perks for $profession at $level, optionally only searching for
	 * a specific buff to the skill $search
	 *
	 * @param string $profession Name of the profession
	 * @param int $level Level of the character
	 * @param string|null $search Name of the skill to search for
	 * @param CommandReply $sendto Where to send the output to
	 * @return void
	 */
	protected function showPerks(string $profession, int $level, ?string $breed, string $search=null, CommandReply $sendto): void {
		$skill = null;
		if ($search !== null) {
			$skills = $this->whatBuffsController->searchForSkill($search);
			$count = count($skills);
			if ($count === 0) {
				$sendto->reply("No skill <highlight>{$search}<end> found.");
				return;
			}
			if ($count > 1) {
				$blob = "<header2>Choose a skill<end>\n";
				foreach ($skills as $skill) {
					$blob .= "<tab>".
						$this->text->makeChatcmd(
							$skill->name,
							"/tell <myname> perks {$level} {$profession} {$skill->name}"
						).
						"\n";
				}
				$msg = $this->text->makeBlob(
					"Matches for <highlight>{$search}<end> ({$count})",
					$blob
				);
				$sendto->reply($msg);
				return;
			}
			$skill = $skills[0];
		}
		$perks = $this->readPerks($profession, $level);
		if (isset($skill)) {
			$perks = $this->filterPerkBuff($perks, $skill);
		}
		if (isset($breed)) {
			$perks = $this->filterBreedPerks($perks, $breed);
		}
		/** @var PerkAggregate[] */
		$perks = array_map([$this, "aggregatePerk"], $perks);
		if (empty($perks)) {
			$msg = "Could not find any perks for level $level $profession.";
			$sendto->reply($msg);
			return;
		}
		/** @var array<string,PerkAggregate[]> */
		$perkGroups = [
			"Profession Perks" => [],
			"Group Perks" => [],
			"General Perks" => [],
		];
		foreach ($perks as $perk) {
			$count = count($perk->professions);
			if ($count === 1) {
				$perkGroups["Profession Perks"] []= $perk;
			} elseif ($count > 13) {
				$perkGroups["General Perks"] []= $perk;
			} else {
				$perkGroups["Group Perks"] []= $perk;
			}
		}
		$blobs = [];
		foreach ($perkGroups as $name => $perks2) {
			usort(
				$perks2,
				function(PerkAggregate $o1, PerkAggregate $o2): int {
					return strcmp($o1->name, $o2->name);
				}
			);
			if (count($perks2)) {
				$blobs []= $this->renderPerkAggGroup($name, $perks2);
			}
		}
		$buffText = isset($skill) ? " buffing {$skill->name}" : "";
		$count = count($perks);
		$msg = $this->text->makeBlob(
			"Perks for a level {$level} {$profession}{$buffText} ({$count})",
			join("\n", $blobs)
		);
		$sendto->reply($msg);
	}

	/**
	 * Render a group of PerkAggregates
	 *
	 * @param string $name
	 * @param PerkAggregate[] $perks
	 * @return string
	 */
	protected function renderPerkAggGroup(string $name, array $perks): string {
		$blobs = [];
		foreach ($perks as $perk) {
			$color = "<font color=#FF6666>";
			if ($perk->expansion === static::ALIEN_INVASION) {
				$color = "<green>";
			}
			$detailsLink = $this->text->makeChatcmd(
				"details",
				"/tell <myname> perks show {$perk->name}"
			);
			$blob = "<pagebreak><tab>{$color}{$perk->name} {$perk->max_level}<end> [{$detailsLink}]\n";
			if (isset($perk->description)) {
				$blob .= "<tab><tab><i>".
					join(
						"</i>\n<tab><tab><i>",
						explode("\n", $perk->description)
					).
					"</i>\n";
			}
			foreach ($perk->buffs as $buff) {
				$blob .= sprintf(
					"<tab><tab>%s <highlight>%+d<end>\n",
					$buff->skill_name,
					$buff->amount
				);
			}
			foreach ($perk->resistances as $res) {
				$blob .= sprintf(
					"<tab><tab>Resist %s <highlight>%d%%<end>\n",
					$res->nanoline,
					$res->amount
				);
			}
			$levels = array_column($perk->actions, "perk_level");
			$maxLevel = max($levels);
			foreach ($perk->actions as $action) {
				$blob .= sprintf(
					"<tab><tab>Add Action at %s: %s%s\n",
					$this->text->alignNumber($action->perk_level, strlen((string)$maxLevel)),
					$this->text->makeItem(
						$action->aodb->lowid,
						$action->aodb->highid,
						$action->aodb->lowql,
						$action->aodb->name
					),
					$action->scaling ? " (<highlight>scaling<end>)" : ""
				);
			}
			$blobs []= $blob;
		}
		return "<header2>{$name}<end>\n\n".
			join("\n", $blobs);
	}

	/**
	 * Expand a skill name into a list of skills,
	 * supporting aliases like AC, Reflect, etc.
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
	 * Parse the perk CSV file into a structured perk array so we can
	 * better insert the data into a database

	 * @return array<string,Perk>
	 */
	public function getPerkInfo(): array {
		$path = __DIR__ . "/perks.csv";
		$lines = explode("\n", file_get_contents($path));
		$perks = [];
		$skillCache = [];
		foreach ($lines as $line) {
			$line = trim($line);

			if (empty($line)) {
				continue;
			}

			$parts = explode("|", $line);
			if (count($parts) < 7) {
				$this->logger->log("ERROR", "Illegal perk entry: {$line}");
				continue;
			}
			[$name, $perkLevel, $expansion, $aoid, $requiredLevel, $profs, $buffs] = $parts;
			$action = $parts[7] ?? null;
			$resistances = $parts[8] ?? null;
			$description = $parts[9] ?? null;
			if ($profs === '*') {
				$profs = "Adv, Agent, Crat, Doc, Enf, Engi, Fix, Keep, MA, MP, NT, Shade, Sol, Tra";
			}
			$perk = $perks[$name];
			if (empty($perk)) {
				$perk = new Perk();
				$perks[$name] = $perk;
				$perk->name = $name;
				$perk->description = $description ? join("\n", explode("\\n", $description)) : null;
				$perk->expansion = $expansion;
			}

			$level = new PerkLevel();
			$perk->levels[$perkLevel] = $level;

			$level->perk_level = (int)$perkLevel;
			$level->required_level = (int)$requiredLevel;
			$level->aoid = (int)$aoid;

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
					$skillSearch = $skillCache[$skill]
						?? $this->whatBuffsController->searchForSkill($skill);
					$skillCache[$skill] = $skillSearch;
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
				$level->action = new PerkLevelAction();
				$level->action->action_id = (int)preg_replace("/\*$/", "", $action, -1, $count);
				$level->action->scaling = $count > 0;
			}
		}
		return $perks;
	}

	/**
	 * @HandlesCommand("perks")
	 * @Matches("/^perks show (.+)$/i")
	 */
	public function showPerkCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$perk = $this->readPerk($args[1]);
		if (!isset($perk)) {
			$msg = "Could not find any perk '<highlight>{$args[1]}<end>'.";
			$sendto->reply($msg);
			return;
		}
		$blob = $this->renderPerk($perk);
		$msg = $this->text->makeBlob("Details for the perk '$perk->name'", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Render a single perk into a blob
	 */
	public function renderPerk(Perk $perk): string {
		$blob = "";
		foreach ($perk->levels as $level) {
			$perkItem = $this->text->makeItem(
				$level->aoid,
				$level->aoid,
				$level->perk_level,
				"details"
			);
			$blob .= "\n<pagebreak><header2>{$perk->name} {$level->perk_level} [{$perkItem}]<end>\n";
			if (count($level->professions) >= 14) {
				$blob .= "<tab>Professions: <highlight>All<end>\n";
			} elseif (count($level->professions) === 1) {
				$blob .= "<tab>Profession: <highlight>{$level->professions[0]}<end>\n";
			} else {
				$blob .= "<tab>Professions: <highlight>".
					join("<end>, <highlight>", $level->professions).
					"<end>\n";
			}
			$blob .= "<tab>Level: <highlight>{$level->required_level}<end>\n";
			foreach ($level->perk_buffs as $buff) {
				$blob .= sprintf(
					"<tab>%s <highlight>%+d%s<end>\n",
					$buff->skill_name,
					$buff->amount,
					$buff->unit
				);
			}
			foreach ($level->perk_resistances as $res) {
				$blob .= "<tab>".
					"Resist {$res->nanoline} <highlight>+{$res->amount}%<end>\n";
			}
			if (isset($level->action)) {
				$blob .= "<tab>Add Action: ".
					$this->text->makeItem(
						$level->action->aodb->lowid,
						$level->action->aodb->highid,
						$level->action->aodb->lowql,
						$level->action->aodb->name
					).
					($level->action->scaling ? " (<highlight>scaling<end>)" : "").
					"\n<tab>".
					$this->text->makeItem(
						$level->action->aodb->lowid,
						$level->action->aodb->highid,
						$level->action->aodb->lowql,
						$this->text->makeImage($level->action->aodb->icon)
					).
					"\n";
			}
		}
		return $blob;
	}

	/**
	 * Compress the detailed information of a perk into a summary
	 * of buffs, actions and resistances, losing level-granularity
	 */
	protected function aggregatePerk(Perk $perk): PerkAggregate {
		$result = new PerkAggregate;
		$result->expansion = $perk->expansion;
		$result->name = $perk->name;
		$result->id = $perk->id;
		$result->description = $perk->description;
		$minLevel = min(array_keys($perk->levels));
		$result->professions = $perk->levels[$minLevel]->professions;
		$result->max_level = max(array_keys($perk->levels));
		/** @var array<int,PerkLevelBuff> */
		$buffs = [];
		/** @var array<int,PerkLevelResistance> */
		$resistances = [];
		foreach ($perk->levels as $level) {
			if (isset($level->action)) {
				$result->actions []= $level->action;
			}
			foreach ($level->perk_buffs as $perkBuff) {
				if (!isset($buffs[$perkBuff->skill_id])) {
					$buffs[$perkBuff->skill_id] = $perkBuff;
				} else {
					$buffs[$perkBuff->skill_id]->amount += $perkBuff->amount;
				}
			}
			foreach ($level->perk_resistances as $perkResistance) {
				if (!isset($resistances[$perkResistance->strain_id])) {
					$resistances[$perkResistance->strain_id] = $perkResistance;
				} else {
					$resistances[$perkResistance->strain_id]->amount += $perkResistance->amount;
				}
			}
		}
		$result->buffs = array_values($buffs);
		usort(
			$result->buffs,
			function (PerkLevelBuff $a, PerkLevelBuff $b): int {
				return strcmp($a->skill_name, $b->skill_name);
			}
		);
		$result->resistances = array_values($resistances);
		usort(
			$result->resistances,
			function (PerkLevelResistance $a, PerkLevelResistance $b): int {
				return strcmp($a->nanoline, $b->nanoline);
			}
		);
		return $result;
	}

	/**
	 * Read all information about a single perk into an object
	 *
	 * @param string $name Name of the perk
	 * @return null|Perk The perk information
	 */
	public function readPerk(string $name): ?Perk {
		/** @var ?Perk */
		$perk = $this->db->table("perk")
			->whereIlike("name", $name)
			->asObj(Perk::class)
			->first();
		if (!isset($perk)) {
			return null;
		}
		$this->db->table("perk_level")
			->where("perk_id", $perk->id)
			->orderBy("perk_level")
			->asObj(PerkLevel::class)
			->each(function (PerkLevel $row) use ($perk) {
				$row->professions = $this->db->table("perk_level_prof")
					->where("perk_level_id", $row->id)
					->select("profession")
					->asObj()
					->pluck("profession")
					->map([$this->util, "getProfessionAbbreviation"])
					->toArray();
				$perk->levels[$row->perk_level] = $row;
			});
		$this->db->table("perk_level AS pl")
			->join("perk_level_actions AS pla", "pl.id", "pla.perk_level_id")
			->join("aodb AS a", "a.lowid", "pla.action_id")
			->where("pl.perk_id", $perk->id)
			->orderBy("pl.perk_level")
			->select("pl.perk_level", "pla.*", "a.*")
			->asObj(PerkLevelAction::class)
			->each(function (PerkLevelAction $perkLevelAction) use ($perk) {
				$item = new AODBEntry();
				foreach (get_class_vars(AODBEntry::class) as $key => $value) {
					$item->{$key} = $perkLevelAction->{$key};
					unset($perkLevelAction->{$key});
				}
				$perkLevelAction->aodb = $item;
				$perk->levels[$perkLevelAction->perk_level]->action = $perkLevelAction;
			});
		$this->db->table("perk_level AS pl")
			->join("perk_level_buffs AS plb", "pl.id", "plb.perk_level_id")
			->join("skills AS s", "s.id", "plb.skill_id")
			->where("pl.perk_id", $perk->id)
			->orderBy("pl.perk_level")
			->orderBy("s.name")
			->select("pl.perk_level", "plb.skill_id", "s.name AS skill_name", "plb.amount", "s.unit")
			->asObj(PerkLevelBuff::class)
			->each(function (PerkLevelBuff $buff) use ($perk) {
				$perk->levels[$buff->perk_level]->perk_buffs []= $buff;
			});
		$this->db->table("perk_level AS pl")
			->join("perk_level_resistances AS plr", "pl.id", "plr.perk_level_id")
			->join("nano_lines AS nl", "nl.strain_id", "plr.strain_id")
			->where("pl.perk_id", $perk->id)
			->orderBy("pl.perk_level")
			->orderBy("nl.name")
			->select("pl.perk_level", "plr.*", "nl.name AS nanoline")
			->asObj(PerkLevelResistance::class)
			->each(function (PerkLevelResistance $res) use ($perk) {
				$perk->levels[$res->perk_level]->perk_resistances []= $res;
			});
		return $perk;
	}

	/**
	 * Read all information about all perks a $profession at $level could perk
	 *
	 * @param string $profession Name of the profession
	 * @param int $level Level at which to check
	 * @return Perk[] The perk information
	 */
	public function readPerks(string $profession, int $level=220): array {
		/** @var array<int,Perk> */
		$perks = $this->db->table("perk AS p")
			->join("perk_level AS pl", "pl.perk_id", "p.id")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->groupBy("p.id", "p.name", "p.expansion", "p.description")
			->select("p.*")
			->asObj(Perk::class)
			->keyBy("id")
			->toArray();
		$this->db->table("perk_level AS pl")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->orderBy("pl.perk_level")
			->select("pl.*", "plp.profession")
			->asObj(PerkLevel::class)
			->reduce(function (array $perks, PerkLevel $perkLevel) {
				/** @var array<int,Perk> $perks */
				$prof = $this->util->getProfessionAbbreviation($perkLevel->profession);
				unset($perkLevel->profession);
				if (!isset($perks[$perkLevel->perk_id]->levels[$perkLevel->perk_level])) {
					$perks[$perkLevel->perk_id]->levels[$perkLevel->perk_level] = $perkLevel;
				}
				$perks[$perkLevel->perk_id]->levels[$perkLevel->perk_level]->professions []= $prof;
				return $perks;
			}, $perks);
		$this->db->table("perk_level AS pl")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->join("perk_level_actions AS pla", "pl.id", "pla.perk_level_id")
			->join("aodb AS a", "a.lowid", "pla.action_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->orderBy("pl.perk_level")
			->select("pl.perk_id", "pl.perk_level", "pla.*", "a.*")
			->asObj(PerkLevelAction::class)
			->each(function(PerkLevelAction $action) use ($perks) {
				$item = new AODBEntry();
				foreach (get_class_vars(AODBEntry::class) as $key => $value) {
					$item->{$key} = $action->{$key};
					unset($action->{$key});
				}
				$action->aodb = $item;
				$perks[$action->perk_id]->levels[$action->perk_level]->action = $action;
			});
		$this->db->table("perk_level AS pl")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->join("perk_level_buffs AS plb", "pl.id", "plb.perk_level_id")
			->join("skills AS s", "s.id", "plb.skill_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->orderBy("pl.perk_level")
			->orderBy("s.name")
			->select("pl.perk_id", "pl.perk_level", "plb.skill_id")
			->addSelect("s.name AS skill_name", "plb.amount", "s.unit")
			->asObj(PerkLevelBuff::class)
			->each(function (PerkLevelBuff $buff) use ($perks) {
				$perks[$buff->perk_id]->levels[$buff->perk_level]->perk_buffs []= $buff;
			});
		$this->db->table("perk_level AS pl")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->join("perk_level_resistances AS plr", "pl.id", "plr.perk_level_id")
			->join("nano_lines AS nl", "nl.strain_id", "plr.strain_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->orderBy("pl.perk_level")
			->orderBy("nl.name")
			->select("pl.perk_id", "pl.perk_level", "plr.*", "nl.name AS nanoline")
			->asObj(PerkLevelResistance::class)
			->each(function (PerkLevelResistance $res) use ($perks) {
				$perks[$res->perk_id]->levels[$res->perk_level]->perk_resistances []= $res;
			});
		return array_values($perks);
	}
}
