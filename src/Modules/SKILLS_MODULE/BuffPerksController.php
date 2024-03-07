<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use function Amp\ByteStream\splitLines;
use function Safe\preg_split;

use Amp\File\Filesystem;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandReply,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	ParamClass\PNonNumberWord,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\{
	ExtBuff,
	ItemsController,
	Skill,
	WhatBuffsController,
};
use Nadybot\Modules\NANO_MODULE\NanoController;
use Revolt\EventLoop;

use Throwable;

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Perks"),
	NCA\DefineCommand(
		command: "perks",
		accessLevel: "guest",
		description: "Show buff perks",
	)
]
class BuffPerksController extends ModuleInstance {
	public const ALIEN_INVASION = "ai";
	public const SHADOWLANDS = "sl";

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Filesystem $fs;

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

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** DB version of perks */
	#[NCA\Setting\Timestamp(mode: 'noedit')]
	public int $perksDBVersion = 0;

	/** @var Collection<Perk> */
	public Collection $perks;

	#[NCA\Setup]
	public function setup(): void {
		EventLoop::defer(function (string $token): void {
			$this->initPerksDatabase();
		});
	}

	/** See which perks are available for your level and profession */
	#[NCA\HandlesCommand("perks")]
	public function buffPerksNoArgsCommand(CmdContext $context): void {
		$whois = $this->playerManager->byName($context->char->name);
		if (empty($whois) || !isset($whois->profession) || !isset($whois->level)) {
			$msg = "Could not retrieve whois info for you.";
			$context->reply($msg);
			return;
		}
		$this->showPerks($whois->profession, $whois->level, $whois->breed, null, $context);
	}

	/**
	 * See which perks are available for a given level and profession
	 *
	 * If you give a search string, it will search for perks buffing this skill/attribute
	 */
	#[NCA\HandlesCommand("perks")]
	public function buffPerksLevelFirstCommand(CmdContext $context, int $level, PNonNumberWord $prof, ?string $search): void {
		$this->buffPerksProfFirstCommand($context, $prof, $level, $search);
	}

	/**
	 * See which perks are available for a given level and profession
	 *
	 * If you give a search string, it will search for perks buffing this skill/attribute
	 */
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

	/** Show detailed information for all of a perk's levels */
	#[NCA\HandlesCommand("perks")]
	public function showPerkCommand(
		CmdContext $context,
		#[NCA\Str("show")]
		string $action,
		string $perkName
	): void {
		$perk = $this->perks->first(function (Perk $perk) use ($perkName): bool {
			return strcasecmp($perk->name, $perkName) === 0;
		});
		if (!isset($perk)) {
			$msg = "Could not find any perk '<highlight>{$perkName}<end>'.";
			$context->reply($msg);
			return;
		}
		$blob = $this->renderPerk($perk);
		$msg = $this->text->makeBlob("Details for the perk '{$perk->name}'", $blob);
		$context->reply($msg);
	}

	/** Render a single perk into a blob */
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
			$buffs = $this->buffHashToCollection($level->buffs);
			foreach ($buffs as $buff) {
				$blob .= sprintf(
					"<tab>%s <highlight>%+d%s<end>\n",
					$buff->skill->name,
					$buff->amount,
					$buff->skill->unit
				);
			}
			$resistances = $this->resistanceHashToCollection($level->resistances);
			foreach ($resistances as $res) {
				$blob .= "<tab>".
					"Resist {$res->nanoline->name} <highlight>+{$res->amount}%<end>\n";
			}
			if (isset($level->action, $level->action->aodb)) {
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
	 * Filter a perk list $perks to only show breed-specific perks for $breed
	 *
	 * @param Perk[] $perks
	 *
	 * @return Perk[]
	 */
	protected function filterBreedPerks(array $perks, string $breed): array {
		$result = [];
		foreach ($perks as $perk) {
			if (
				preg_match("/(Primary|Secondary) Genome/", $perk->name)
				&& !preg_match("/^{$breed}/", $perk->name)
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
	 *
	 * @return Perk[]
	 */
	protected function filterPerkBuff(array $perks, Skill $skill): array {
		// Filter out all perks that don't buff anything in $skill
		/** @var Perk[] */
		$result = array_values(array_filter(
			$perks,
			function (Perk $perk) use ($skill): bool {
				// Delete all buffs except for the searched skill
				foreach ($perk->levels as &$level) {
					$level = clone $level;
					$level->resistances = [];
					$level->action = null;
					if (($level->buffs[$skill->id]??0) > 0) {
						$level->buffs = [$skill->id => $level->buffs[$skill->id]];
					} else {
						$level->buffs = [];
					}
				}
				// Completely delete all perk levels not buffing the searched skill
				$perk->levels = array_filter(
					$perk->levels,
					function (PerkLevel $level): bool {
						return count($level->buffs) > 0;
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
	 * @param string       $profession Name of the profession
	 * @param int          $level      Level of the character
	 * @param string|null  $search     Name of the skill to search for
	 * @param CommandReply $sendto     Where to send the output to
	 */
	protected function showPerks(string $profession, int $level, ?string $breed, ?string $search, CommandReply $sendto): void {
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
		$perks = $this->perks->filter(function (Perk $perk) use ($profession, $level): bool {
			return in_array($profession, $perk->levels[1]->professions)
				&& $perk->levels[1]->required_level <= $level;
		});
		$perks = $perks->map(function (Perk $perk) use ($profession, $level): Perk {
			$p = clone $perk;
			$p->levels = (new Collection($p->levels))->filter(
				function (PerkLevel $pl) use ($profession, $level): bool {
					return in_array($profession, $pl->professions)
						&& $pl->required_level <= $level;
				}
			)->toArray();
			return $p;
		})->toArray();
		if (isset($skill)) {
			$perks = $this->filterPerkBuff($perks, $skill);
		}
		if (isset($breed)) {
			$perks = $this->filterBreedPerks($perks, $breed);
		}

		/** @var PerkAggregate[] */
		$perks = array_map([$this, "aggregatePerk"], $perks);
		if (empty($perks)) {
			$msg = "Could not find any perks for level {$level} {$profession}.";
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
				function (PerkAggregate $o1, PerkAggregate $o2): int {
					return strcmp($o1->name, $o2->name);
				}
			);
			if (count($perks2)) {
				$blobs []= $this->renderPerkAggGroup($name, ...$perks2);
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

	/** Render a group of PerkAggregates */
	protected function renderPerkAggGroup(string $name, PerkAggregate ...$perks): string {
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
			$buffs = $this->buffHashToCollection($perk->buffs);
			foreach ($buffs as $buff) {
				$blob .= sprintf(
					"<tab><tab>%s <highlight>%+d%s<end>\n",
					$buff->skill->name,
					$buff->amount,
					$buff->skill->unit,
				);
			}
			$resistances = $this->resistanceHashToCollection($perk->resistances);
			foreach ($resistances as $resistance) {
				$blob .= sprintf(
					"<tab><tab>Resist %s <highlight>%d%%<end>\n",
					$resistance->nanoline->name,
					$resistance->amount,
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
					$action->aodb->getLink(),
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
				"Add. Proj. Dam.",
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
				"Radiation AC",
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
	 * Compress the detailed information of a perk into a summary
	 * of buffs, actions and resistances, losing level-granularity
	 */
	protected function aggregatePerk(Perk $perk): PerkAggregate {
		$result = new PerkAggregate();
		$result->expansion = $perk->expansion;
		$result->name = $perk->name;
		$result->description = $perk->description;

		/** @var int */
		$minLevel = (new Collection($perk->levels))->keys()->min();
		$result->professions = $perk->levels[$minLevel]->professions;
		$result->max_level = (new Collection($perk->levels))->keys()->max();

		/** @var array<int,int> */
		$buffs = [];

		/** @var array<int,int> */
		$resistances = [];
		foreach ($perk->levels as $level) {
			if (isset($level->action)) {
				$result->actions []= $level->action;
			}
			foreach ($level->buffs as $skillId => $amount) {
				if (!isset($buffs[$skillId])) {
					$buffs[$skillId] = $amount;
				} else {
					$buffs[$skillId] += $amount;
				}
			}
			foreach ($level->resistances as $strainId => $amount) {
				if (!isset($resistances[$strainId])) {
					$resistances[$strainId] = $amount;
				} else {
					$resistances[$strainId] += $amount;
				}
			}
		}
		$result->buffs = $buffs;
		$result->resistances = $resistances;
		return $result;
	}

	private function initPerksDatabase(): void {
		$startTs = microtime(true);
		$path = __DIR__ . "/perks.csv";

		$mtime = $this->fs->getModificationTime($path);
		$dbVersion = $this->perksDBVersion;

		$perkInfo = $this->getPerkInfo();
		$this->perks = new Collection($perkInfo);
		$empty = !$this->db->table("perk")->exists();
		if (($dbVersion >= $mtime) && !$empty) {
			return;
		}
		$dbTs = microtime(true);
		$this->logger->notice("(Re)building perk database...");

		$this->db->awaitBeginTransaction();
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
		$dbDuration = round((microtime(true) - $dbTs) * 1000, 1);
		$parseDuration = round(($dbTs - $startTs) * 1000, 1);
		$this->logger->notice("Finished (re)building perk database in {parse_duration}ms + {db_duration}ms", [
			"parse_duration" => $parseDuration,
			"db_duration" => $dbDuration,
		]);
	}

	/**
	 * Parse the perk CSV file into a structured perk array so we can
	 * better insert the data into a database
	 *
	 * @return array<string,Perk>
	 */
	private function getPerkInfo(): array {
		$path = __DIR__ . "/perks.csv";

		$fileHandle = $this->fs->openFile($path, "r");
		$reader = splitLines($fileHandle);
		$perks = [];
		$skillCache = [];
		foreach ($reader as $line) {
			$line = trim($line);

			if (empty($line)) {
				continue;
			}

			$parts = explode("|", $line);
			if (count($parts) < 7) {
				$this->logger->error("Illegal perk entry: {line}", ["line" => $line]);
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
				$perk->description = isset($description) ? join("\n", explode("\\n", $description)) : null;
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
					$this->logger->info("Error parsing profession: '{prof}'", [
						"prof" => $prof,
					]);
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
						$this->logger->info("Error parsing skill: '{skill}'", [
							"skill" => $skill,
						]);
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
				$level->action->perk_level = $level->perk_level;
				$item = $this->itemsController->getByIDs($level->action->action_id)->first();
				if (!isset($item)) {
					continue;
				}
				$level->action->aodb = $item;
			}
		}
		$fileHandle->close();
		return $perks;
	}

	/**
	 * @param array<int,int> $buffs
	 *
	 * @return Collection<ExtBuff>
	 */
	private function buffHashToCollection(array $buffs): Collection {
		$result = new Collection();
		foreach ($buffs as $skillId => $amount) {
			$skill = $this->itemsController->getSkillByID($skillId);
			if (!isset($skill)) {
				continue;
			}
			$buff = new ExtBuff();
			$buff->skill = $skill;
			$buff->amount = $amount;
			$result []= $buff;
		}
		return $result->sort(function (ExtBuff $b1, ExtBuff $b2): int {
			return strnatcmp($b1->skill->name, $b2->skill->name);
		});
	}

	/**
	 * @param array<int,int> $resistances
	 *
	 * @return Collection<ExtResistance>
	 */
	private function resistanceHashToCollection(array $resistances): Collection {
		$result = new Collection();
		foreach ($resistances as $strainId => $amount) {
			$nanoline = $this->nanoController->getNanoLineById($strainId);
			if (!isset($nanoline)) {
				continue;
			}
			$resistance = new ExtResistance();
			$resistance->nanoline = $nanoline;
			$resistance->amount = $amount;
			$result []= $resistance;
		}
		return $result->sort(function (ExtResistance $b1, ExtResistance $b2): int {
			return strnatcmp($b1->nanoline->name, $b2->nanoline->name);
		});
	}
}
