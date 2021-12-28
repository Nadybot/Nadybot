<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
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
use Nadybot\Core\ParamClass\PNonNumberWord;
use Nadybot\Modules\{
	ITEMS_MODULE\AODBEntry,
	ITEMS_MODULE\Skill,
	ITEMS_MODULE\WhatBuffsController,
};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Nadybot\Modules\NANO_MODULE\NanoController;
use Throwable;

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Perks"),
	NCA\DefineCommand(
		command: "perks",
		accessLevel: "all",
		description: "Show buff perks",
		help: "perks.txt"
	)
]
class BuffPerksController {
	public const ALIEN_INVASION = "ai";
	public const SHADOWLANDS = "sl";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public WhatBuffsController $whatBuffsController;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Inject]
	public NanoController $nanoController;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public Collection $perks;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "perks_db_version",
			description: "perks_db_version",
			mode: 'noedit',
			type: 'text',
			value: "0"
		);
		$this->timer->callLater(0, [$this, "initPerksDatabase"]);
	}

	public function initPerksDatabase(): void {
		if ($this->db->inTransaction()) {
			$this->timer->callLater(0, [$this, "initPerksDatabase"]);
			return;
		}
		$path = __DIR__ . "/perks.csv";
		$mtime = @filemtime($path);
		$dbVersion = 0;
		if ($this->settingManager->exists("perks_db_version")) {
			$dbVersion = (int)$this->settingManager->get("perks_db_version");
		}
		$perkInfo = $this->getPerkInfo();
		$this->perks = new Collection($perkInfo);
		$empty = !$this->db->table("perk")->exists();
		if (($mtime === false || $dbVersion >= $mtime) && !$empty) {
			return;
		}
		$this->logger->notice("(Re)building perk database...");

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
			$this->db->table("perk_level_prof")->chunkInsert($profInserts);
			$this->db->table("perk_level_resistances")->chunkInsert($resInserts);
			$this->db->table("perk_level_buffs")->chunkInsert($buffInserts);
			$newVersion = max($mtime ?: time(), $dbVersion);
			$this->settingManager->save("perks_db_version", (string)$newVersion);
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
		$this->db->commit();
	}

	#[NCA\HandlesCommand("perks")]
	public function buffPerksNoArgsCommand(CmdContext $context): void {
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($context): void {
				if (empty($whois) || !isset($whois->profession) || !isset($whois->level)) {
					$msg = "Could not retrieve whois info for you.";
					$context->reply($msg);
					return;
				}
				$this->showPerks($whois->profession, $whois->level, $whois->breed, null, $context);
			},
			$context->char->name
		);
	}

	#[NCA\HandlesCommand("perks")]
	public function buffPerksLevelFirstCommand(CmdContext $context, int $level, PNonNumberWord $prof, ?string $search): void {
		$this->buffPerksProfFirstCommand($context, $prof, $level, $search);
	}

	#[NCA\HandlesCommand("perks")]
	public function buffPerksProfFirstCommand(CmdContext $context, PNonNumberWord $prof, int $level, ?string $search): void {
		$profession = $this->util->getProfessionName($prof());
		if ($profession === "") {
			$msg = "Could not find profession <highlight>{$prof}<end>.";
			$context->reply($msg);
			return;
		}
		$this->showPerks($profession, $level, null, $search, $context);
	}

	/**
	 * Filter a perk list $perks to only show breed-specific perks for $breed
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
				if (!isset($res->nanoline)) {
					continue;
				}
				$blob .= sprintf(
					"<tab><tab>Resist %s <highlight>%d%%<end>\n",
					$res->nanoline,
					$res->amount
				);
			}
			$levels = array_column($perk->actions, "perk_level");
			$maxLevel = 0;
			if (count($levels)) {
				$maxLevel = max($levels);
			}
			foreach ($perk->actions as $action) {
				if (!isset($action->perk_level) || !isset($action->aodb)) {
					continue;
				}
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
				$this->logger->error("Illegal perk entry: {$line}");
				continue;
			}
			[$name, $perkLevel, $expansion, $aoid, $requiredLevel, $profs, $buffs] = $parts;
			$action = $parts[7] ?? null;
			$resistances = $parts[8] ?? null;
			$description = $parts[9] ?? null;
			if ($profs === '*') {
				$profs = "Adv, Agent, Crat, Doc, Enf, Engi, Fix, Keep, MA, MP, NT, Shade, Sol, Tra";
			}
			$perk = $perks[$name]??null;
			if (empty($perk)) {
				$perk = new Perk();
				$perks[$name] = $perk;
				$perk->name = $name;
				$perk->description = $description ? join("\n", explode("\\n", $description)) : null;
				$perk->expansion = $expansion;
			}

			$level = new PerkLevel();
			$perk->levels[(int)$perkLevel] = $level;

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
				$resistances = preg_split("/\s*,\s*/", $resistances??"");
				foreach ($resistances as $resistance) {
					[$strainId, $amount] = preg_split("/\s*:\s*/", $resistance);
					$level->resistances[(int)$strainId] = (int)$amount;
				}
			}
			if (strlen($action??'')) {
				$level->action = new PerkLevelAction();
				$level->action->action_id = (int)preg_replace("/\*$/", "", $action??"", -1, $count);
				$level->action->scaling = $count > 0;
			}
		}
		return $perks;
	}

	#[NCA\HandlesCommand("perks")]
	public function showPerkCommand(CmdContext $context, #[NCA\Str("show")] string $action, string $perkName): void {
		$perk = $this->readPerk($perkName);
		if (!isset($perk)) {
			$msg = "Could not find any perk '<highlight>{$perkName}<end>'.";
			$context->reply($msg);
			return;
		}
		$blob = $this->renderPerk($perk);
		$msg = $this->text->makeBlob("Details for the perk '$perk->name'", $blob);
		$context->reply($msg);
	}

	/**
	 * Render a single perk into a blob
	 */
	public function renderPerk(Perk $perk): string {
		$blob = "";
		foreach ($perk->levels as $level) {
			if (!isset($level->aoid)) {
				continue;
			}
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
			if (isset($level->action) && isset($level->action->aodb)) {
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
		/** @var array<int,ExtPerkLevelBuff> */
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
			function (ExtPerkLevelBuff $a, ExtPerkLevelBuff $b): int {
				return strcmp($a->skill_name, $b->skill_name);
			}
		);
		$result->resistances = array_values($resistances);
		usort(
			$result->resistances,
			function (PerkLevelResistance $a, PerkLevelResistance $b): int {
				return strcmp($a->nanoline??"", $b->nanoline??"");
			}
		);
		return $result;
	}

	/**
	 * Read all information about a single perk into an object
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
			->each(function (PerkLevel $row) use ($perk): void {
				$row->professions = $this->db->table("perk_level_prof")
					->where("perk_level_id", $row->id)
					->select("profession")
					->asObj()
					->pluck("profession")
					->map([$this->util, "getProfessionAbbreviation"])
					->toArray();
				$perk->levels[$row->perk_level] = $row;
			});
		$levels = $this->db->table("perk_level AS pl")
			->join("perk_level_actions AS pla", "pl.id", "pla.perk_level_id")
			->where("pl.perk_id", $perk->id)
			->orderBy("pl.perk_level")
			->select("pl.perk_level", "pla.*")
			->asObj(PerkLevelAction::class);
		$items = $this->itemsController->getByIDs(...$levels->pluck("action_id")->toArray())
			->keyBy("lowid");
		$levels->each(function (PerkLevelAction $perkLevelAction) use ($perk, $items): void {
			$perkLevelAction->aodb = $items->get($perkLevelAction->action_id);
			$perk->levels[$perkLevelAction->perk_level??0]->action = $perkLevelAction;
		});
		$eplb = $this->db->table("perk_level AS pl")
			->join("perk_level_buffs AS plb", "pl.id", "plb.perk_level_id")
			->where("pl.perk_id", $perk->id)
			->orderBy("pl.perk_level")
			->select("pl.perk_level", "plb.skill_id", "plb.amount")
			->asObj(ExtPerkLevelBuff::class);
		$skills = $this->itemsController->getSkillByIDs(...$eplb->pluck("skill_id")->toArray())
			->keyBy("id");
		$eplb->each(function (ExtPerkLevelBuff $buff) use ($skills): void {
			/** @var Skill */
			$skill = $skills->get($buff->skill_id);
			$buff->skill_name = $skill->name;
			$buff->unit = $skill->unit;
		});
		$eplb = $eplb->sortBy("skill_name");
		$eplb->each(function (ExtPerkLevelBuff $buff) use ($perk): void {
			$perk->levels[$buff->perk_level]->perk_buffs []= $buff;
		});
		$plr = $this->db->table("perk_level AS pl")
			->join("perk_level_resistances AS plr", "pl.id", "plr.perk_level_id")
			->where("pl.perk_id", $perk->id)
			->orderBy("pl.perk_level")
			->select("pl.perk_level", "plr.*")
			->asObj(PerkLevelResistance::class);
		$nanolines = $this->nanoController
			->getNanoLinesByIds(...$plr->pluck("strain_id")->toArray())
			->keyBy("strain_id");
		$plr->each(function (PerkLevelResistance $res) use ($nanolines): void {
			$res->nanoline = $nanolines->get($res->strain_id)->name;
		});
		$plr = $plr->sortBy("nanoline");
		$plr->each(function (PerkLevelResistance $res) use ($perk): void {
			$perk->levels[$res->perk_level]->perk_resistances []= $res;
		});
		return $perk;
	}

	/**
	 * Read all information about all perks a $profession at $level could perk
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
			->reduce(function (array $perks, PerkLevel $perkLevel): array {
				/** @var array<int,Perk> $perks */
				$prof = $this->util->getProfessionAbbreviation($perkLevel->profession);
				unset($perkLevel->profession);
				if (!isset($perks[$perkLevel->perk_id]->levels[$perkLevel->perk_level])) {
					$perks[$perkLevel->perk_id]->levels[$perkLevel->perk_level] = $perkLevel;
				}
				$perks[$perkLevel->perk_id]->levels[$perkLevel->perk_level]->professions []= $prof;
				return $perks;
			}, $perks);
		$pla = $this->db->table("perk_level AS pl")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->join("perk_level_actions AS pla", "pl.id", "pla.perk_level_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->orderBy("pl.perk_level")
			->select("pl.perk_id", "pl.perk_level", "pla.*")
			->asObj(PerkLevelAction::class);
		$items = $this->itemsController
			->getByIDs(...$pla->pluck("action_id")->toArray())
			->keyBy("lowid");
		$pla->each(function(PerkLevelAction $action) use ($perks, $items) {
			$action->aodb = $items->get($action->action_id);
			$perks[$action->perk_id]->levels[$action->perk_level??0]->action = $action;
		});
		$plb = $this->db->table("perk_level AS pl")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->join("perk_level_buffs AS plb", "pl.id", "plb.perk_level_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->orderBy("pl.perk_level")
			->select("pl.perk_id", "pl.perk_level", "plb.skill_id", "plb.amount")
			->asObj(ExtPerkLevelBuff::class);
		$skills = $this->itemsController
			->getSkillByIDs(...$plb->pluck("skill_id")->toArray())
			->keyBy("id");
		$plb->each(function (ExtPerkLevelBuff $buff) use ($skills): void {
			/** @var Skill */
			$skill = $skills->get($buff->skill_id);
			$buff->skill_name = $skill->name;
			$buff->unit = $skill->unit;
		});
		$plb = $plb->sortBy("skill_name");
		$plb->each(function (ExtPerkLevelBuff $buff) use ($perks): void {
			$perks[$buff->perk_id]->levels[$buff->perk_level]->perk_buffs []= $buff;
		});
		$plr = $this->db->table("perk_level AS pl")
			->join("perk_level_prof AS plp", "pl.id", "plp.perk_level_id")
			->join("perk_level_resistances AS plr", "pl.id", "plr.perk_level_id")
			->where("pl.required_level", "<=", $level)
			->where("plp.profession", $profession)
			->orderBy("pl.perk_level")
			->select("pl.perk_id", "pl.perk_level", "plr.*")
			->asObj(PerkLevelResistance::class);

		$nanolines = $this->nanoController
			->getNanoLinesByIds(...$plr->pluck("strain_id")->toArray())
			->keyBy("strain_id");
		$plr->each(function (PerkLevelResistance $res) use ($nanolines): void {
			$res->nanoline = $nanolines->get($res->strain_id)->name;
		});
		$plr = $plr->sortBy("nanoline");
		$plr->each(function (PerkLevelResistance $res) use ($perks): void {
			$perks[$res->perk_id]->levels[$res->perk_level]->perk_resistances []= $res;
		});
		return array_values($perks);
	}

	/** @return Collection<PerkLevelBuff> */
	public function getPerkBuffs(?int $perkLevelId=null, null|int|array $skillId=null): Collection {
		$query = $this->db->table("perk_level_buffs");
		if (isset($perkLevelId)) {
			$query->where("perk_level_id", $perkLevelId);
		}
		if (is_int($skillId)) {
			$query->where("skill_id", $skillId);
		} elseif (isset($skillId)) {
			$query->whereIn("skill_id", $skillId);
		}
		return $query->asObj(PerkLevelBuff::class);
	}

	/**
	 * @param null|int|int[] $perkId
	 * @param null|int|int[] $perkLevelId
	 * @return Collection<PerkLevel>
	 */
	public function getPerkLevels(null|int|array $perkId=null, null|int|array $perkLevelId=null): Collection {
		$query = $this->db->table("perk_level");
		if (is_array($perkLevelId)) {
			$query->whereIn("id", $perkLevelId);
		} elseif (is_int($perkLevelId)) {
			$query->where("id", $perkLevelId);
		}
		if (is_int($perkId)) {
			$query->where("perk_id", $perkId);
		} elseif (isset($perkId)) {
			$query->whereIn("perk_id", $perkId);
		}
		return $query->asObj(PerkLevel::class);
	}
}
