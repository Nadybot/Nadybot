<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use function Amp\async;
use function Amp\ByteStream\splitLines;
use function Safe\{preg_match, preg_split};

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\Filesystem;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PNonNumberWord,
	Safe,
	SettingManager,
	Text,
	Types\CommandReply,
	Types\Profession,
	Types\SettingMode,
};
use Nadybot\Modules\ITEMS_MODULE\{
	ExtBuff,
	ItemsController,
	Skill,
	WhatBuffsController,
};
use Nadybot\Modules\NANO_MODULE\NanoController;
use Psr\Log\LoggerInterface;

use Throwable;

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Perks'),
	NCA\DefineCommand(
		command: 'perks',
		accessLevel: 'guest',
		description: 'Show buff perks',
	)
]
class BuffPerksController extends ModuleInstance {
	public const ALIEN_INVASION = 'ai';
	public const SHADOWLANDS = 'sl';

	/** DB version of perks */
	#[NCA\Setting\Timestamp(mode: SettingMode::NoEdit)]
	public int $perksDBVersion = 0;

	/** @var Collection<string,Perk> */
	public Collection $perks;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private WhatBuffsController $whatBuffsController;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private ItemsController $itemsController;

	#[NCA\Inject]
	private NanoController $nanoController;

	#[NCA\Setup]
	public function setup(): void {
		async($this->initPerksDatabase(...))->catch(Nadybot::asyncErrorHandler(...));
	}

	/** See which perks are available for your level and profession */
	#[NCA\HandlesCommand('perks')]
	public function buffPerksNoArgsCommand(CmdContext $context): void {
		$whois = $this->playerManager->byName($context->char->name);
		if (!isset($whois) || !isset($whois->profession) || !isset($whois->level)) {
			$msg = 'Could not retrieve whois info for you.';
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
	#[NCA\HandlesCommand('perks')]
	public function buffPerksLevelFirstCommand(CmdContext $context, int $level, PNonNumberWord $prof, ?string $search): void {
		$this->buffPerksProfFirstCommand($context, $prof, $level, $search);
	}

	/**
	 * See which perks are available for a given level and profession
	 *
	 * If you give a search string, it will search for perks buffing this skill/attribute
	 */
	#[NCA\HandlesCommand('perks')]
	public function buffPerksProfFirstCommand(CmdContext $context, PNonNumberWord $prof, int $level, ?string $search): void {
		try {
			$profession = Profession::byName($prof());
		} catch (Exception) {
			$msg = "Could not find profession <highlight>{$prof}<end>.";
			$context->reply($msg);
			return;
		}
		$this->showPerks($profession, $level, null, $search, $context);
	}

	/** Show detailed information for all of a perk's levels */
	#[NCA\HandlesCommand('perks')]
	public function showPerkCommand(
		CmdContext $context,
		#[NCA\Str('show')] string $action,
		string $perkName
	): void {
		$perk = $this->perks->first(static function (Perk $perk) use ($perkName): bool {
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
		$blob = '';
		foreach ($perk->levels as $level) {
			if (!isset($level->aoid)) {
				continue;
			}
			$perkItem = Text::makeItem(
				$level->aoid,
				$level->aoid,
				$level->perk_level,
				'details'
			);
			$blob .= "\n<pagebreak><header2>{$perk->name} {$level->perk_level} [{$perkItem}]<end>\n";
			if (count($level->professions) >= 14) {
				$blob .= "<tab>Professions: <highlight>All<end>\n";
			} elseif (count($level->professions) === 1) {
				$blob .= "<tab>Profession: <highlight>{$level->professions[0]}<end>\n";
			} else {
				$blob .= '<tab>Professions: <highlight>'.
					implode('<end>, <highlight>', $level->professions).
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
				$blob .= '<tab>'.
					"Resist {$res->nanoline->name} <highlight>+{$res->amount}%<end>\n";
			}
			if (isset($level->action, $level->action->aodb)) {
				$aodb = $level->action->aodb;
				$blob .= '<tab>Add Action: '.
					$aodb->getLink(ql: $aodb->getLowQL()).
					($level->action->scaling ? ' (<highlight>scaling<end>)' : '').
					"\n<tab>".
					$aodb->getLink(ql: $aodb->getLowQL(), text: $aodb->getIcon()).
					"\n";
			}
		}
		return $blob;
	}

	/**
	 * Filter a perk list $perks to only show breed-specific perks for $breed
	 *
	 * @param iterable<Perk> $perks
	 *
	 * @return list<Perk>
	 */
	protected function filterBreedPerks(iterable $perks, string $breed): array {
		$result = [];
		foreach ($perks as $perk) {
			if (
				preg_match('/(Primary|Secondary) Genome/', $perk->name)
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
	 * @return list<Perk>
	 */
	protected function filterPerkBuff(array $perks, Skill $skill): array {
		// Filter out all perks that don't buff anything in $skill
		/** @var list<Perk> */
		$result = array_values(array_filter(
			$perks,
			static function (Perk $perk) use ($skill): bool {
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
					static function (PerkLevel $level): bool {
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
	 * @param Profession   $profession Name of the profession
	 * @param int          $level      Level of the character
	 * @param string|null  $search     Name of the skill to search for
	 * @param CommandReply $sendto     Where to send the output to
	 */
	protected function showPerks(Profession $profession, int $level, ?string $breed, ?string $search, CommandReply $sendto): void {
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
				foreach ($skills as $skill2) {
					$blob .= '<tab>'.
						Text::makeChatcmd(
							$skill2->name,
							"/tell <myname> perks {$level} {$profession->value} {$skill2->name}"
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
		$perks = $this->perks->filter(static function (Perk $perk) use ($profession, $level): bool {
			return in_array($profession->value, $perk->levels[1]->professions, true)
				&& $perk->levels[1]->required_level <= $level;
		});
		$perks = $perks->map(static function (Perk $perk) use ($profession, $level): Perk {
			$p = clone $perk;
			$p->levels = collect($p->levels)->filter(
				static function (PerkLevel $pl) use ($profession, $level): bool {
					return in_array($profession->value, $pl->professions, true)
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

		$perks = array_map($this->aggregatePerk(...), $perks);
		if (!count($perks)) {
			$msg = "Could not find any perks for level {$level} {$profession->value}.";
			$sendto->reply($msg);
			return;
		}

		/** @var array<string,list<PerkAggregate>> */
		$perkGroups = [
			'Profession Perks' => [],
			'Group Perks' => [],
			'General Perks' => [],
		];
		foreach ($perks as $perk) {
			$count = count($perk->professions);
			if ($count === 1) {
				$perkGroups['Profession Perks'] []= $perk;
			} elseif ($count > 13) {
				$perkGroups['General Perks'] []= $perk;
			} else {
				$perkGroups['Group Perks'] []= $perk;
			}
		}
		$blobs = [];
		foreach ($perkGroups as $name => $perks2) {
			usort(
				$perks2,
				static function (PerkAggregate $o1, PerkAggregate $o2): int {
					return strcmp($o1->name, $o2->name);
				}
			);
			if (count($perks2)) {
				$blobs []= $this->renderPerkAggGroup($name, ...$perks2);
			}
		}
		$buffText = isset($skill) ? " buffing {$skill->name}" : '';
		$count = count($perks);
		$msg = $this->text->makeBlob(
			"Perks for a level {$level} {$profession->value}{$buffText} ({$count})",
			implode("\n", $blobs)
		);
		$sendto->reply($msg);
	}

	/** Render a group of PerkAggregates */
	protected function renderPerkAggGroup(string $name, PerkAggregate ...$perks): string {
		$blobs = [];
		foreach ($perks as $perk) {
			$color = '<font color=#FF6666>';
			if ($perk->expansion === static::ALIEN_INVASION) {
				$color = '<green>';
			}
			$detailsLink = Text::makeChatcmd(
				'details',
				"/tell <myname> perks show {$perk->name}"
			);
			$blob = "<pagebreak><tab>{$color}{$perk->name} {$perk->max_level}<end> [{$detailsLink}]\n";
			if (isset($perk->description)) {
				$blob .= '<tab><tab><i>'.
					implode(
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
			$levels = array_column($perk->actions, 'perk_level');
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
					Text::alignNumber($action->perk_level, strlen((string)$maxLevel)),
					$action->aodb->getLink(),
					$action->scaling ? ' (<highlight>scaling<end>)' : ''
				);
			}
			$blobs []= $blob;
		}
		return "<header2>{$name}<end>\n\n".
			implode("\n", $blobs);
	}

	/**
	 * Expand a skill name into a list of skills,
	 * supporting aliases like AC, Reflect, etc.
	 *
	 * @return list<string>
	 */
	protected function expandSkill(string $skill): array {
		if ($skill === 'Add. Dmg.') {
			return [
				'Add. Cold Dam.',
				'Add. Chem Dam.',
				'Add. Energy Dam.',
				'Add. Fire Dam.',
				'Add. Melee Dam.',
				'Add. Poison Dam.',
				'Add. Rad. Dam.',
				'Add. Proj. Dam.',
			];
		} elseif ($skill === 'AC') {
			return [
				'Melee/ma AC',
				'Disease AC',
				'Fire AC',
				'Cold AC',
				'Imp/Proj AC',
				'Energy AC',
				'Chemical AC',
				'Radiation AC',
			];
		} elseif ($skill === 'Shield') {
			return [
				'ShieldProjectileAC',
				'ShieldMeleeAC',
				'ShieldEnergyAC',
				'ShieldChemicalAC',
				'ShieldRadiationAC',
				'ShieldColdAC',
				'ShieldNanoAC',
				'ShieldFireAC',
				'ShieldPoisonAC',
			];
		} elseif ($skill === 'Reflect') {
			return [
				'ReflectProjectileAC',
				'ReflectMeleeAC',
				'ReflectEnergyAC',
				'ReflectChemicalAC',
				'ReflectRadiationAC',
				'ReflectColdAC',
				'ReflectNanoAC',
				'ReflectFireAC',
				'ReflectPoisonAC',
			];
		}
		return [$skill];
	}

	/**
	 * Compress the detailed information of a perk into a summary
	 * of buffs, actions and resistances, losing level-granularity
	 */
	protected function aggregatePerk(Perk $perk): PerkAggregate {
		/** @var int */
		$minLevel = collect($perk->levels)->keys()->min();
		$result = new PerkAggregate(
			expansion: $perk->expansion,
			name: $perk->name,
			description: $perk->description,
			professions: $perk->levels[$minLevel]->professions,
			max_level: collect($perk->levels)->keys()->max(),
		);

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
		$path = __DIR__ . \DIRECTORY_SEPARATOR . 'perks.csv';

		$mtime = $this->fs->getModificationTime($path);
		$dbVersion = $this->perksDBVersion;

		$perkInfo = $this->getPerkInfo();
		$this->perks = collect($perkInfo);
		$empty = !$this->db->table(Perk::getTable())->exists();
		if (($dbVersion >= $mtime) && !$empty) {
			return;
		}
		$dbTs = microtime(true);
		$this->logger->notice('(Re)building perk database...');

		$this->db->awaitBeginTransaction();
		try {
			$this->db->table(Perk::getTable())->truncate();
			$this->db->table(PerkLevel::getTable())->truncate();
			$this->db->table(PerkLevelProf::getTable())->truncate();
			$this->db->table(PerkLevelBuff::getTable())->truncate();
			$this->db->table(PerkLevelAction::getTable())->truncate();
			$this->db->table(PerkLevelResistance::getTable())->truncate();

			$profInserts = [];
			$resInserts = [];
			$buffInserts = [];
			foreach ($perkInfo as $perk) {
				$this->db->insert($perk);

				foreach ($perk->levels as $level) {
					$this->db->insert($level);

					foreach ($level->professions as $profession) {
						$profInserts []= [
							'perk_level_id' => $level->id->toString(),
							'profession' => $profession,
						];
					}

					foreach ($level->resistances as $strain => $amount) {
						$resInserts []= [
							'perk_level_id' => $level->id->toString(),
							'strain_id' => $strain,
							'amount' => $amount,
						];
					}

					if ($level->action) {
						$this->db->insert($level->action);
					}

					foreach ($level->buffs as $skillId => $amount) {
						$buffInserts []= [
							'perk_level_id' => $level->id->toString(),
							'skill_id' => $skillId,
							'amount' => $amount,
						];
					}
				}
			}
			$this->db->table(PerkLevelProf::getTable())->chunkInsert($profInserts);
			$this->db->table(PerkLevelResistance::getTable())->chunkInsert($resInserts);
			$this->db->table(PerkLevelBuff::getTable())->chunkInsert($buffInserts);
			$newVersion = max($mtime, $dbVersion);
			$this->settingManager->save('perks_db_version', (string)$newVersion);
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
		$this->db->commit();
		$dbDuration = round((microtime(true) - $dbTs) * 1_000, 1);
		$parseDuration = round(($dbTs - $startTs) * 1_000, 1);
		$this->logger->notice('Finished (re)building perk database in {parse_duration}ms + {db_duration}ms', [
			'parse_duration' => $parseDuration,
			'db_duration' => $dbDuration,
		]);
	}

	/**
	 * Parse the perk CSV file into a structured perk array so we can
	 * better insert the data into a database
	 *
	 * @return array<string,Perk>
	 */
	private function getPerkInfo(): array {
		$path = __DIR__ . '/perks.csv';

		$fileHandle = $this->fs->openFile($path, 'r');
		$reader = splitLines($fileHandle);

		/** @var array<string,Perk> */
		$perks = [];
		$skillCache = [];
		foreach ($reader as $line) {
			$line = trim($line);

			if ($line === '') {
				continue;
			}

			$parts = explode('|', $line);
			if (count($parts) < 7) {
				$this->logger->error('Illegal perk entry: {line}', ['line' => $line]);
				continue;
			}
			[$name, $perkLevel, $expansion, $aoid, $requiredLevel, $profs, $buffs] = $parts;
			$action = $parts[7] ?? null;
			$resistances = $parts[8] ?? null;
			$description = $parts[9] ?? null;
			if ($profs === '*') {
				$profs = 'Adv, Agent, Crat, Doc, Enf, Engi, Fix, Keep, MA, MP, NT, Shade, Sol, Tra';
			}
			$perk = $perks[$name]??null;
			if (!isset($perk)) {
				$perk = new Perk(
					name: $name,
					description: isset($description) ? implode("\n", explode('\\n', $description)) : null,
					expansion: $expansion,
				);
				$perks[$name] = $perk;
			}

			$level = new PerkLevel(
				perk_id: $perk->id,
				perk_level: (int)$perkLevel,
				required_level: (int)$requiredLevel,
				aoid: (int)$aoid,
			);

			$perk->levels[(int)$perkLevel] = $level;

			$professions = explode(',', $profs);
			foreach ($professions as $prof) {
				$profession = Profession::tryByName(trim($prof));
				if (!isset($profession)) {
					$this->logger->info("Error parsing profession: '{prof}'", [
						'prof' => $prof,
					]);
				} else {
					$level->professions []= $profession->value;
				}
			}

			$buffs = explode(',', $buffs);
			foreach ($buffs as $buff) {
				$buff = trim($buff);
				$pos = strrpos($buff, ' ');
				if ($pos === false) {
					continue;
				}

				$skillName = trim(substr($buff, 0, $pos + 1));
				$amount = trim(substr($buff, $pos + 1));
				$skills = $this->expandSkill($skillName);
				foreach ($skills as $skill) {
					$skillSearch = $skillCache[$skill]
						?? $this->whatBuffsController->searchForSkill($skill);
					$skillCache[$skill] = $skillSearch;
					if (count($skillSearch) !== 1) {
						$this->logger->info("Error parsing skill: '{skill}'", [
							'skill' => $skill,
						]);
					} else {
						$level->buffs[$skillSearch[0]->id] = (int)$amount;
					}
				}
			}

			if (strlen($resistances??'')) {
				$resistances = preg_split("/\s*,\s*/", $resistances??'');
				foreach ($resistances as $resistance) {
					[$strainId, $amount] = preg_split("/\s*:\s*/", $resistance);
					$level->resistances[(int)$strainId] = (int)$amount;
				}
			}
			if (strlen($action??'')) {
				$actionId = (int)Safe::pregReplace("/\*$/", '', $action??'', -1, $count);
				$level->action = new PerkLevelAction(
					action_id: $actionId,
					scaling: $count > 0,
					perk_level: $level->perk_level,
					perk_level_id: $level->id,
					aodb: $this->itemsController->getByIDs($actionId)->first(),
				);
			}
		}
		$fileHandle->close();
		return $perks;
	}

	/**
	 * @param array<int,int> $buffs
	 *
	 * @return Collection<int,ExtBuff>
	 */
	private function buffHashToCollection(array $buffs): Collection {
		/** @var Collection<int,ExtBuff> */
		$result = new Collection();
		foreach ($buffs as $skillId => $amount) {
			$skill = $this->itemsController->getSkillByID($skillId);
			if (!isset($skill)) {
				continue;
			}
			$result []= new ExtBuff(
				skill: $skill,
				amount: $amount,
			);
		}
		return $result->sort(static function (ExtBuff $b1, ExtBuff $b2): int {
			return strnatcmp($b1->skill->name, $b2->skill->name);
		});
	}

	/**
	 * @param array<int,int> $resistances
	 *
	 * @return Collection<int,ExtResistance>
	 */
	private function resistanceHashToCollection(array $resistances): Collection {
		/** @var Collection<int,ExtResistance> */
		$result = new Collection();
		foreach ($resistances as $strainId => $amount) {
			$nanoline = $this->nanoController->getNanoLineById($strainId);
			if (!isset($nanoline)) {
				continue;
			}
			$result []= new ExtResistance(
				nanoline: $nanoline,
				amount: $amount,
			);
		}
		return $result->sort(static function (ExtResistance $b1, ExtResistance $b2): int {
			return strnatcmp($b1->nanoline->name, $b2->nanoline->name);
		});
	}
}
