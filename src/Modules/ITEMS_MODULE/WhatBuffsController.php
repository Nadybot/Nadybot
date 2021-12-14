<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	CommandManager,
	CommandReply,
	DB,
	DBRow,
	Http,
	LoggerWrapper,
	QueryBuilder,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PWord;

/**
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "whatbuffs",
		accessLevel: "all",
		description: "Find items or nanos that buff an ability or skill",
		help: "whatbuffs.txt"
	),
	NCA\DefineCommand(
		command: "whatbuffsfroob",
		accessLevel: "all",
		description: "Find froob-friendly items or nanos that buff an ability or skill",
		help: "whatbuffs.txt",
		alias: "wbf"
	)
]
class WhatBuffsController {

	public string $moduleName;

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Buff");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/item_buffs.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/skills.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/skill_alias.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/item_types.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/buffs.csv");

		$this->settingManager->add(
			$this->moduleName,
			'whatbuffs_display',
			'How to mark if an item can only be equipped left or right',
			'edit',
			'options',
			'2',
			'Do not mark;L/R;L-Wrist/R-Wrist',
			'0;1;2',
		);
		$this->settingManager->add(
			$this->moduleName,
			'whatbuffs_show_unique',
			'How to mark unique items',
			'edit',
			'options',
			'2',
			'Do not mark;U;Unique',
			'0;1;2',
		);
		$this->settingManager->add(
			$this->moduleName,
			'whatbuffs_show_nodrop',
			'How to mark nodrop items',
			'edit',
			'options',
			'0',
			'Do not mark;ND;Nodrop',
			'0;1;2',
		);
	}

	#[NCA\HandlesCommand("whatbuffs")]
	public function whatbuffsCommand(CmdContext $context): void {
		$this->showSkillChoice($context, false);
	}

	#[NCA\HandlesCommand("whatbuffsfroob")]
	public function whatbuffsFroobCommand(CmdContext $context): void {
		$this->showSkillChoice($context, true);
	}

	public function showSkillChoice(CommandReply $sendto, bool $froobFriendly): void {
		$command = "whatbuffs" . ($froobFriendly ? "froob" : "");
		$suffix = $froobFriendly ? "Froob" : "";
		$blob = "<header2>Choose a skill<end>\n";
		/** @var Collection<Skill> */
		$skills = $this->db->table('skills')
			->join('item_buffs', 'item_buffs.attribute_id', '=', 'skills.id')
			->orderBy('skills.name')
			->select('skills.name')
			->distinct()
			->asObj(Skill::class);
		foreach ($skills as $skill) {
			$blob .= "<tab>" . $this->text->makeChatcmd($skill->name, "/tell <myname> {$command} $skill->name") . "\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = $this->text->makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	#[
		NCA\HandlesCommand("whatbuffs"),
		NCA\HandlesCommand("whatbuffsfroob")
	]
	public function whatbuffsOneWordCommand(CmdContext $context, PWord $search): void {
		$command = explode(" ", $context->message)[0];
		$froobFriendly = strtolower($command) === "whatbuffsfroob";
		$type = ucfirst(strtolower($this->resolveLocationAlias($search())));

		if ($this->verifySlot($type)) {
			$this->showSkillsBuffingType($type, $froobFriendly, $command, $context);
			return;
		}
		$this->handleOtherComandline($froobFriendly, $context, $search());
	}

	public function showSkillsBuffingType(string $type, bool $froobFriendly, string $command, CommandReply $sendto): void {
		if (!$this->verifySlot($type)) {
			$msg = "Could not find any items of type <highlight>$type<end>.";
			$sendto->reply($msg);
			return;
		}
		if ($type === 'Nanoprogram') {
			$query = $this->db->table('buffs');
			$query
				->join('item_buffs', 'item_buffs.item_id', '=', 'buffs.id')
				->join('skills', 'item_buffs.attribute_id', '=', 'skills.id')
				->where(function(QueryBuilder $query) {
					$query->whereIn('skills.name', ['SkillLockModifier', '% Add. Nano Cost'])
						->orWhere('item_buffs.amount', '>', 0);
				})
				->groupBy('skills.name')
				->havingRaw($query->rawFunc('COUNT', 1) . " > 0")
				->orderBy('skills.name')
				->select([
					"skills.name AS skill",
					$query->rawFunc('COUNT', 1, 'num')
				]);
			if ($froobFriendly) {
				$query->where('buffs.froob_friendly', '=', true);
			}
			$data = $query->asObj();
		} elseif ($type === 'Perk') {
			if ($froobFriendly) {
				$sendto->reply("Froobs don't have perks.");
				return;
			}
			$query = $this->db->table('perk');
			$query
				->join("perk_level", "perk_level.perk_id", "=", "perk.id")
				->join("perk_level_buffs", "perk_level_buffs.perk_level_id", "=", "perk_level.id")
				->join('skills', 'perk_level_buffs.skill_id', '=', 'skills.id')
				->where(function(QueryBuilder $query) {
					$query->whereIn('skills.name', ['SkillLockModifier', '% Add. Nano Cost'])
						->orWhere('perk_level_buffs.amount', '>', 0);
				})
				->groupBy('skills.name')
				->havingRaw($query->rawFunc('COUNT', 1) . " > 0")
				->orderBy('skills.name')
				->select([
					"skills.name AS skill",
					$query->rawFunc('COUNT', 1, 'num')
				]);
			$data = $query->asObj();
		} else {
			$query = $this->db->table('aodb');
			$query
				->join('item_types', 'item_types.item_id', '=', 'aodb.highid')
				->join('item_buffs', 'item_buffs.item_id', '=', 'aodb.highid')
				->join('skills', 'item_buffs.attribute_id', '=', 'skills.id')
				->where('item_types.item_type', '=', $type)
				->whereNotIn('aodb.name', ['Brad Test Nano'])
				->groupBy('skills.name')
				->havingRaw($query->rawFunc('COUNT', 1) . " > 0")
				->orderBy('skills.name')
				->select([
					"skills.name AS skill",
					$query->rawFunc('COUNT', 1, 'num')
				]);
			if ($froobFriendly) {
				$query->where('aodb.froob_friendly', '=', true);
			}
			$data = $query->asObj();
		}
		$blob = "<header2>Choose the skill to buff<end>\n";
		foreach ($data as $row) {
			$blob .= "<tab>" . $this->text->makeChatcmd(ucfirst($row->skill), "/tell <myname> {$command} $type $row->skill") . " ($row->num)\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$suffix = $froobFriendly ? "Froob" : "";
		$msg = $this->text->makeBlob("WhatBuffs{$suffix} {$type} - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	#[NCA\HandlesCommand("whatbuffs")]
	public function whatbuffs5Command(CmdContext $context, string $search): void {
		$this->handleOtherComandline(false, $context, $search);
	}

	#[NCA\HandlesCommand("whatbuffsfroob")]
	public function whatbuffsfroob5Command(CmdContext $context, string $search): void {
		$this->handleOtherComandline(true, $context, $search);
	}

	public function handleOtherComandline(bool $froobFriendly, CmdContext $context, string $search): void {
		$tokens = explode(" ", $search);
		$firstType = ucfirst(strtolower($this->resolveLocationAlias($tokens[0])));
		$lastType = ucfirst(strtolower($this->resolveLocationAlias($tokens[count($tokens) - 1])));

		if ($this->verifySlot($firstType) && !preg_match("/^smt\.?$/i", $tokens[1]??"")) {
			array_shift($tokens);
			$msg = $this->showSearchResults($firstType, join(" ", $tokens), $froobFriendly);
			$context->reply($msg);
			return;
		} elseif ($this->verifySlot($lastType)) {
			array_pop($tokens);
			$msg = $this->showSearchResults($lastType, join(" ", $tokens), $froobFriendly);
			$context->reply($msg);
			return;
		}
		$skill = $search;
		$command = "whatbuffs" . ($froobFriendly ? "froob" : "");
		$suffix = $froobFriendly ? "Froob" : "";

		$data = $this->searchForSkill($skill);
		$count = count($data);

		$blob = "";
		if ($count === 0) {
			$msg = "Could not find skill <highlight>$skill<end>.";
			$context->reply($msg);
			return;
		}
		if ($count > 1) {
			$blob .= "<header2>Choose a skill<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab>" . $this->text->makeChatcmd(ucfirst($row->name), "/tell <myname> {$command} {$row->name}") . "\n";
			}
			$blob .= "\nItem Extraction Info provided by AOIA+";
			$msg = $this->text->makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
			$context->reply($msg);
			return;
		}
		$skillId = $data[0]->id;
		$skillName = $data[0]->name;
		$itemQuery = $this->db->table('aodb');
		$itemQuery
			->join('item_types', 'item_types.item_id', '=', 'aodb.highid')
			->join('item_buffs', 'item_buffs.item_id', '=', 'aodb.highid')
			->join('skills', 'skills.id', '=', 'item_buffs.attribute_id')
			->where('skills.id', '=', $skillId)
			->where(function(QueryBuilder $query) {
				$query->whereIn('skills.name', ['SkillLockModifier', '% Add. Nano Cost'])
					->orWhere('item_buffs.amount', '>', 0);
			})
			->groupBy('aodb.name', 'item_types.item_type', 'aodb.lowql', 'aodb.highql', 'item_buffs.amount')
			->select('item_types.item_type');
		$nanoQuery = $this->db->table('buffs');
		$nanoQuery
			->join('item_buffs', 'item_buffs.item_id', '=', 'buffs.id')
			->join('skills', 'skills.id', '=', 'item_buffs.attribute_id')
			->where('skills.id', '=', $skillId)
			->where(function(QueryBuilder $query) {
				$query->whereIn('skills.name', ['SkillLockModifier', '% Add. Nano Cost'])
					->orWhere('item_buffs.amount', '>', 0);
			})
			->select(
				$nanoQuery->raw(
					$nanoQuery->grammar->quoteString('Nanoprogram').
					' AS ' . $nanoQuery->grammar->wrap('item_type')
				)
			);
		$perkQuery = $this->db->table('perk_level_buffs');
		$perkQuery
			->join('perk_level', 'perk_level.id', '=', 'perk_level_buffs.perk_level_id')
			->join('perk', 'perk.id', '=', 'perk_level.perk_id')
			->join('skills', 'skills.id', '=', 'perk_level_buffs.skill_id')
			->where(function(QueryBuilder $query) {
				$query->whereIn('skills.name', ['SkillLockModifier', '% Add. Nano Cost'])
					->orWhere('perk_level_buffs.amount', '>', 0);
			})
			->groupBy('perk.name')
			->select(
				$perkQuery->raw(
					$perkQuery->grammar->quoteString('Perk').
					' AS ' . $perkQuery->grammar->wrap('item_type')
				)
			);
		if ($froobFriendly) {
			$itemQuery->where('aodb.froob_friendly', '=', true);
			$nanoQuery->where('buffs.froob_friendly', '=', true);
		}
		$innerQuery = $itemQuery
			->unionAll($nanoQuery);
		if (!$froobFriendly) {
			$innerQuery->unionAll($perkQuery);
		}
		$query = $this->db->fromSub($innerQuery, "foo");
		$query
			->groupBy('foo.item_type')
			->orderBy('foo.item_type')
			->select(['foo.item_type', $query->rawFunc('COUNT', '*', 'num')]);
		$data = $query->asObj();
		if (count($data) === 0) {
			$msg = "There are currently no known items or nanos buffing <highlight>{$skillName}<end>";
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Choose buff type<end>\n";
		foreach ($data as $row) {
			$blob .= "<tab>" . $this->text->makeChatcmd(ucfirst($row->item_type), "/tell <myname> {$command} {$row->item_type} {$skillName}") . " ($row->num)\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = $this->text->makeBlob("WhatBuffs{$suffix} {$skillName} - Choose Type", $blob);
		$context->reply($msg);
	}

	/**
	 * Gives a blob with all items buffing $skill in slot $category
	 * @return string|string[]
	 */
	public function getSearchResults(string $category, Skill $skill, bool $froobFriendly) {
		$suffix = $froobFriendly ? "Froob" : "";
		if ($category === 'Nanoprogram') {
			$query = $this->db->table('buffs AS b');
			$query
				->join("item_buffs AS ib", "ib.item_id", "b.id")
				->join("skills AS s", "s.id", "ib.attribute_id")
				->leftJoin("aodb AS a", "a.lowid", "b.use_id")
				->where("s.id", $skill->id)
				->where(function(QueryBuilder $query) {
					$query->whereIn("s.name", ['SkillLockModifier', '% Add. Nano Cost'])
						->orWhere("ib.amount", ">", 0);
				})->whereNotIn("b.name", [
					'Ineptitude Transfer',
					'Accumulated Interest',
					'Unforgiven Debts',
					'Payment Plan'
				])->orderByDesc("ib.amount")
				->orderBy("b.name")
				->select([
					"b.*", "ib.amount", "a.lowid", "a.highid",
					"a.lowql", "a.name AS use_name", "s.unit"
				]);
			if ($froobFriendly) {
				$query->where("b.froob_friendly", true);
			}
			/** @var Collection<NanoBuffSearchResult> */
			$data = $query->asObj(NanoBuffSearchResult::class);
			if ($data->isNotEmpty() && $data->last()->amount < 0) {
				$data = $data->reverse();
			}
			$result = $this->formatBuffs($data->toArray(), $skill);
		} elseif ($category === 'Perk') {
			if ($froobFriendly) {
				return "Froobs don't have perks.";
			}
			$query = $this->db->table('perk AS p');
			/** @var Collection<PerkBuffSearchResult> */
			$data = $query
				->join('perk_level AS pl', 'pl.perk_id', 'p.id')
				->join('perk_level_prof AS plp', 'plp.perk_level_id', 'pl.id')
				->join('perk_level_buffs AS plb', 'plb.perk_level_id', 'pl.id')
				->join('skills AS s', 's.id', 'plb.skill_id')
				->where('s.id', $skill->id)
				->where(function(QueryBuilder $query) {
					$query->whereIn("s.name", ['SkillLockModifier', '% Add. Nano Cost'])
						->orWhere("plb.amount", ">", 0);
				})->groupBy("p.name", "p.expansion", "pl.perk_level", "s.unit", "pl.id")
				->orderBy("p.name")
				->orderBy("pl.perk_level")
				->select("p.name", "p.expansion", "pl.perk_level", "pl.id", "s.unit")
				->addSelect($query->colFunc("MIN", "plb.amount", "amount"))
				->asObj(PerkBuffSearchResult::class)
				->each(function(PerkBuffSearchResult $result) {
					$result->profs = $this->db->table("perk_level_prof")
						->where("perk_level_id", $result->id)
						->select("profession")
						->orderBy("profession")
						->asObj()
						->pluck("profession")
						->join(",");
				});
			$data = $this->generatePerkBufflist($data->toArray());
			$result = $this->formatPerkBuffs($data, $skill);
		} else {
			$query = $this->db->table("aodb AS a");
			$query
				->join("item_types AS i", "i.item_id", "a.highid")
				->join("item_buffs AS b", "b.item_id", "a.highid")
				->leftJoin("item_buffs AS b2", "b2.item_id", "a.lowid")
				->leftJoin("weapon_attributes AS wa", "wa.id", "a.highid")
				->join("skills AS s", function(JoinClause $join) {
					$join->on("b.attribute_id", "s.id")
						->on("b2.attribute_id", "s.id");
				})->where("i.item_type", $category)
				->where("s.id", $skill->id)
				->where(function(QueryBuilder $query) {
					$query->whereIn("s.name", ['SkillLockModifier', '% Add. Nano Cost'])
						->orWhere("b.amount", ">", 0);
				})->groupBy([
					"a.name", "a.lowql", "a.highql", "b.amount", "b2.amount",
					"wa.multi_m", "wa.multi_r", "a.lowid", "a.highid", "a.icon",
					"a.froob_friendly", "a.slot", "a.flags", "s.unit"
				])->orderByDesc($query->colFunc("ABS", "b.amount"))
				->orderByDesc("name")
				->select([
					"a.*", "b.amount", "b2.amount AS low_amount",
					"wa.multi_m", "wa.multi_r", "s.unit"
				]);
			if ($froobFriendly) {
				$query->where("a.froob_friendly", true);
			}
			/** @var Collection<ItemBuffSearchResult> */
			$data = $query->asObj(ItemBuffSearchResult::class);
			if ($data->isNotEmpty() && $data->last()->amount < 0) {
				$data = $data->reverse();
			}
			$result = $this->formatItems($data->toArray(), $skill, $category);
		}

		[$count, $blob] = $result;
		if ($count === 0) {
			$msg = "No items found of type <highlight>$category<end> that buff <highlight>$skill->name<end>.";
		} else {
			$blob .= "\nItem Extraction Info provided by AOIA+";
			$msg = $this->text->makeBlob("WhatBuffs{$suffix} - {$category} {$skill->name} ({$count})", $blob);
		}
		return $msg;
	}

	protected function generatePerkBufflist(array $data): array {
		/** @var array<string,PerkBuffSearchResult> */
		$result = [];
		foreach ($data as $perk) {
			if (!isset($result[$perk->name])) {
				$result[$perk->name] = $perk;
			} else {
				$result[$perk->name]->amount += $perk->amount;
			}
			$profs = explode(",", $perk->profs);
			foreach ($profs as $prof) {
				$result[$perk->name]->profMax[$prof] += $perk->amount;
			}
		}
		$data = [];
		// If a perk has different max levels for profs, we create one entry for each of the
		// buff levels, so 1 perk can appear several times with different max buffs
		foreach ($result as $perk => $perkData) {
			/** @var PerkBuffSearchResult $perkData */
			$diffValues = array_unique(array_values($perkData->profMax));
			foreach ($diffValues as $buffValue) {
				$profs = [];
				foreach ($perkData->profMax as $prof => $profBuff) {
					if ($profBuff === $buffValue) {
						$profs []= $prof;
					}
				}
				$obj = clone $perkData;
				$obj->amount = $buffValue;
				$obj->profs = join(",", $profs);
				$obj->profMax = [];
				$data []= $obj;
			}
		}
		usort(
			$data,
			function(PerkBuffSearchResult $p1, PerkBuffSearchResult $p2): int {
				return ($p2->amount <=> $p1->amount) ?: strcmp($p1->name??"", $p2->name??"");
			}
		);
		return $data;
	}

	/**
	 * Check if a slot (fingers, chest) exists
	 */
	public function verifySlot(string $type): bool {
		return $this->db->table('item_types')
			->where('item_type', $type)
			->exists() || strtolower($type) === 'perk';
	}

	/**
	 * Search for all skills and skill aliases matching $skill
	 * @return Skill[]
	 */
	public function searchForSkill(string $skill): array {
		// check for exact match first, in order to disambiguate
		// between Bow and Bow special attack
		/** @var Collection<Skill> */
		$results = $this->db->table('skills')
			->whereIlike('name', $skill)
			->select(['id', 'name'])
			->distinct()
			->union(
				$this->db->table('skill_alias')
					->join('skills', 'skills.id', 'skill_alias.id')
					->whereIlike('skill_alias.name', $skill)
					->select(['skill_alias.id', 'skills.name'])
					->distinct()
			)->asObj(Skill::class);
		if ($results->count() === 1) {
			return $results->toArray();
		}

		$skillsQuery = $this->db->table('skills')->select(['id', 'name'])->distinct();
		$aliasQuery = $this->db->table('skill_alias')->select(['id', 'name'])->distinct();

		$tmp = explode(" ", $skill);
		$this->db->addWhereFromParams($skillsQuery, $tmp, 'name');
		$this->db->addWhereFromParams($aliasQuery, $tmp, 'name');

		return $this->db
			->fromSub(
				$skillsQuery->union($aliasQuery),
				"foo"
			)
			->groupBy("id", "name")
			->orderBy("name")
			->select(["id", "name"])
			->asObj(Skill::class)
			->toArray();
	}

	public function showItemLink(DBRow $item, $ql): string {
		return $this->text->makeItem($item->lowid, $item->highid, $ql, $item->name);
	}

	/**
	 * Format a list of item buff search results
	 * @param ItemBuffSearchResult[] $items The items that matched the search
	 * @return (int|string)[]
	 */
	public function formatItems(array $items, Skill $skill, string $category) {
		$showUniques = $this->settingManager->getInt('whatbuffs_show_unique');
		$showNodrops = $this->settingManager->getInt('whatbuffs_show_nodrop');
		$blob = "<header2>" . ucfirst($this->locationToItem($category)) . " that buff {$skill->name}<end>\n";
		$maxBuff = 0;
		$itemMapping = [];
		foreach ($items as $item) {
			if ($item->amount === $item->low_amount) {
				$item->highql = $item->lowql;
			}
			// Some items are not in game with the maximum possible QL
			// Replace the shown QL with the maximum possible QL
			$item->maxql = $item->highql;
			$item->maxamount = $item->amount;
			if (
				$item->highql > 250 && (
					strpos($item->name, " Filigree Ring set with a ") !== false ||
					strncmp($item->name, "Universal Advantage - ", 22) === 0
				)
			) {
				$item->amount = $this->util->interpolate($item->lowql, $item->highql, $item->low_amount??$item->amount, $item->amount, 250);
				$item->highql = 250;
			}
			$maxBuff = (int)max($maxBuff, abs($item->amount));
			if ($item->lowid === $item->highid) {
				$itemMapping[$item->lowid] = $item;
			}
		}
		$multiplier = 1;
		if (in_array($skill->name, ["SkillLockModifier", "% Add. Nano Cost"])) {
			$multiplier = -1;
		}
		usort(
			$items,
			function(ItemBuffSearchResult $a, ItemBuffSearchResult $b) use ($multiplier): int {
				return ($b->amount <=> $a->amount) * $multiplier;
			}
		);
		$ignoreItems = [];
		foreach ($items as $item) {
			if ($item->highid !== $item->lowid &&isset($itemMapping[$item->highid])) {
				$item->highid = $itemMapping[$item->highid]->highid;
				$item->highql = $itemMapping[$item->highid]->highql;
				$ignoreItems []= $itemMapping[$item->highid];
			}
		}
		$maxDigits = strlen((string)$maxBuff);
		foreach ($items as $item) {
			if (in_array($item, $ignoreItems, true)) {
				continue;
			}
			$sign = ($item->amount > 0) ? '+' : '-';
			$prefix = "<tab>" . $sign.$this->text->alignNumber(abs($item->amount), $maxDigits, 'highlight');
			$blob .= $prefix . $item->unit . "  ";
			$blob .= $this->getSlotPrefix($item, $category);
			$blob .= $this->showItemLink($item, $item->highql);
			if ($item->amount > $item->low_amount) {
				$blob .= " ($item->low_amount - $item->amount)";
				$bestQlCommands = $this->commandManager->get('bestql', 'msg');
				if ($bestQlCommands && $bestQlCommands[0]->status) {
					$link = $this->text->makeItem($item->lowid, $item->highid, 0, $item->name);
					$blob .= " " . $this->text->makeChatcmd(
						"Breakpoints",
						"/tell <myname> bestql $item->lowql $item->low_amount $item->maxql $item->maxamount ".
						$link
					);
				}
			}
			if ($item->flags & Flag::UNIQUE && $showUniques) {
				$blob .= $showUniques === 1 ? " U" : " Unique";
			}
			if ($item->flags & Flag::NODROP && $showNodrops) {
				$blob .= $showNodrops === 1 ? " ND" : " Nodrop";
			}
			$blob .= "\n";
		}

		$count = count($items);
		return [$count, $blob];
	}

	protected function getSlotPrefix(ItemBuffSearchResult $item, string $category): string {
		$markSetting = $this->settingManager->getInt('whatbuffs_display');
		$result = "";
		if ($item->multi_m !== null || $item->multi_r !== null) {
			$handsMask = Slot::LHAND|Slot::RHAND;
			if (($item->slot & $handsMask) === $handsMask) {
				return "2x ";
			} elseif (($item->slot & $handsMask) === Slot::LHAND) {
				$result = "L-Hand ";
			} else {
				$result = "R-Hand ";
			}
		} elseif ($category === "Arms") {
			if (($item->slot & (Slot::LARM|Slot::RARM)) === Slot::LARM) {
				$result = "L-Arm ";
			} elseif (($item->slot & (Slot::LARM|Slot::RARM)) === Slot::RARM) {
				$result = "R-Arm ";
			}
		} elseif ($category === "Wrists") {
			if (($item->slot & (Slot::LWRIST|Slot::RWRIST)) === Slot::LWRIST) {
				$result = "L-Wrist ";
			} elseif (($item->slot & (Slot::LWRIST|Slot::RWRIST)) === Slot::RWRIST) {
				$result = "R-Wrist ";
			}
		} elseif ($category === "Fingers") {
			if (($item->slot & (Slot::LFINGER|Slot::RFINGER)) === Slot::LFINGER) {
				$result = "L-Finger ";
			} elseif (($item->slot & (Slot::LFINGER|Slot::RFINGER)) === Slot::RFINGER) {
				$result = "R-Finger ";
			}
		} elseif ($category === "Shoulders") {
			if (($item->slot & (Slot::LSHOULDER|Slot::RSHOULDER)) === Slot::LSHOULDER) {
				$result = "L-Shoulder ";
			} elseif (($item->slot & (Slot::LSHOULDER|Slot::RSHOULDER)) === Slot::RSHOULDER) {
				$result = "R-Shoulder ";
			}
		}
		if ($markSetting === 0) {
			return "";
		}
		if ($markSetting === 1 && strlen($result) > 1) {
			return substr($result, 0, 1) . " ";
		}
		return $result;
	}

	/**
	 * @param NanoBuffSearchResult[] $items
	 * @return NanoBuffSearchResult[]
	 */
	public function groupDrainsAndWrangles(array $items): array {
		$result = [];
		$groups = [
			'/(Divest|Deprive) Skills.*Transfer/',
			'/(Ransack|Plunder) Skills.*Transfer/',
			'/^Umbral Wrangler/',
			'/^Team Skill Wrangler/',
			'/^Skill Wrangler/',
		];
		$highestOfGroup = [];
		foreach ($items as $item) {
			$skip = false;
			foreach ($groups as $group) {
				if (preg_match($group, $item->name)) {
					if (array_key_exists($group, $highestOfGroup)) {
						$highestOfGroup[$group]->low_ncu = $item->ncu;
						$highestOfGroup[$group]->low_amount = $item->amount;
						$skip = true;
					} else {
						$highestOfGroup[$group] = $item;
					}
				}
			}
			if ($skip === false) {
				$result []= $item;
			}
		}
		return $result;
	}

	/**
	 * @param PerkBuffSearchResult[] $perks
	 * @return (int|string)[]
	 */
	public function formatPerkBuffs(array $perks, Skill $skill) {
		$blob = "<header2>Perks that buff {$skill->name}<end>\n";
		$maxBuff = 0;
		foreach ($perks as $perk) {
			$maxBuff = max($maxBuff, abs($perk->amount));
		}
		$maxDigits = strlen((string)$maxBuff);
		foreach ($perks as $perk) {
			$color = $perk->expansion === "ai" ? "<green>" : "<highlight>";
			if (substr_count($perk->profs, ",") < 13) {
				$perk->profs = join(
					"<end>, {$color}",
					array_map(
						[$this->util, "getProfessionAbbreviation"],
						explode(",", $perk->profs)
					)
				);
			} else {
				$perk->profs = "All";
			}
			$sign = ($perk->amount > 0) ? '+' : '-';
			$prefix = "<tab>{$sign}" . $this->text->alignNumber(abs($perk->amount), $maxDigits, 'highlight');
			$blob .= $prefix . "{$perk->unit}  {$perk->name} ({$color}{$perk->profs}<end>)\n";
		}

		$count = count($perks);
		return [$count, $blob];
	}

	/**
	 * @param NanoBuffSearchResult[] $items
	 * @return (int|string)[]
	 */
	public function formatBuffs(array $items, Skill $skill) {
		$items = array_values(
			array_filter(
				$items,
				function (NanoBuffSearchResult $nano): bool {
					return !preg_match("/^Composite .+ Expertise \(\d hours\)$/", $nano->name);
				}
			)
		);
		$blob = "<header2>Nanoprograms that buff {$skill->name}<end>\n";
		$maxBuff = 0;
		foreach ($items as $item) {
			$maxBuff = max($maxBuff, abs($item->amount));
		}
		$maxDigits = strlen((string)$maxBuff);
		$items = $this->groupDrainsAndWrangles($items);
		foreach ($items as $item) {
			if ($item->ncu === 999) {
				$item->ncu = 0;
			}
			$prefix = "<tab>" . $this->text->alignNumber($item->amount, $maxDigits, 'highlight');
			$blob .= $prefix . $item->unit . "  <a href='itemid://53019/{$item->id}'>{$item->name}</a> ";
			if (isset($item->low_ncu) && isset($item->low_amount)) {
				$blob .= "($item->low_ncu NCU (<highlight>$item->low_amount<end>) - $item->ncu NCU (<highlight>$item->amount<end>))";
			} else {
				$blob .= "($item->ncu NCU)";
			}
			if ($item->lowid > 0 && isset($item->lowql)) {
				$blob .= " (from " . $this->text->makeItem($item->lowid, $item->highid??$item->lowid, $item->lowql, $item->use_name??"") . ")";
			}
			$blob .= "\n";
		}

		$count = count($items);
		return [$count, $blob];
	}

	/**
	 * Show what buffs $skillName in slot $category
	 * @return string|string[]
	 */
	public function showSearchResults(string $category, string $skillName, bool $froobFriendly) {
		$category = ucfirst(strtolower($category));

		$skills = $this->searchForSkill($skillName);
		$count = count($skills);

		if ($count === 0) {
			$msg = "Could not find any skills matching <highlight>{$skillName}<end>.";
		} elseif ($count === 1) {
			$skill = $skills[0];
			$msg = $this->getSearchResults($category, $skill, $froobFriendly);
		} else {
			$blob = '';
			$command = "whatbuffs" . ($froobFriendly ? "froob" : "");
			$suffix = $froobFriendly ? "Froob" : "";
			foreach ($skills as $skill) {
				$blob .= $this->text->makeChatcmd(ucfirst($skill->name), "/tell <myname> {$command} {$category} {$skill->name}") . "\n";
			}
			$msg = $this->text->makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
		}

		return $msg;
	}

	/** Convert a location (arms) to item type (sleeves) */
	protected function locationToItem(string $location): string {
		$location = strtolower($location);
		$map = [
			"arms" => "sleeves",
			"back" => "back-items",
			"deck" => "deck-items",
			"feet" => "boots",
			"fingers" => "rings",
			"hands" => "gloves",
			"head" => "helmets",
			"hud" => "HUD-items",
			"legs" => "pants",
			"neck" => "neck-items",
			"wrists" => "wrist items",
			"use" => "usable items",
		];
		if (isset($map[$location])) {
			return $map[$location];
		}
		return rtrim($location, "s") . 's';
	}

	/** Resolve aliases for locations like arms and sleeves  into proper locations */
	protected function resolveLocationAlias(string $location): string {
		$location = strtolower($location);
		$map = [
			"arm" => "arms",
			"sleeve" => "arms",
			"sleeves" => "arms",
			"ncu" => "deck",
			"contracts" => "contract",
			"belt" => "deck",
			"boots" => "feet",
			"foot" => "feet",
			"ring" => "fingers",
			"rings" => "fingers",
			"finger" => "fingers",
			"gloves" => "hands",
			"glove" => "hands",
			"gauntlets" => "hands",
			"gauntlet" => "hands",
			"hand" => "hands",
			"helmets" => "head",
			"helmet" => "head",
			"pants" => "legs",
			"pant" => "legs",
			"perks" => "perk",
			"weapons" => "weapon",
			"shoulder" => "shoulders",
			"wrist" => "wrists",
		];
		return $map[$location] ?? $location;
	}
}
