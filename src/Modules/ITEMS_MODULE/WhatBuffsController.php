<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use function Safe\preg_match;
use Closure;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\Types\{CarrySlot, EnumBitfield, ItemFlag, Skill, WearSlot};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DB,
	ModuleInstance,
	QueryBuilder,
	Text,
	Types\AOItemSpec,
	Types\CommandReply,
	Types\Profession,
	Util,
};
use Nadybot\Modules\SKILLS_MODULE\{
	BuffPerksController,
	Perk,
	SkillsController,
};

#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Buff'),
	NCA\DefineCommand(
		command: 'whatbuffs',
		accessLevel: 'guest',
		description: 'Find items or nanos that buff an ability or skill',
	),
	NCA\DefineCommand(
		command: 'whatbuffsfroob',
		accessLevel: 'guest',
		description: 'Find froob-friendly items or nanos that buff an ability or skill',
		alias: 'wbf'
	),
]
class WhatBuffsController extends ModuleInstance {
	/** How to mark if an item can only be equipped left or right */
	#[NCA\Setting\Options(options: [
		'Do not mark' => 0,
		'L/R' => 1,
		'L-Wrist/R-Wrist' => 2,
	])]
	public int $whatbuffsDisplay = 2;

	/** How to mark unique items */
	#[NCA\Setting\Options(options: [
		'Do not mark' => 0,
		'U' => 1,
		'Unique' => 2,
	])]
	public int $whatbuffsShowUnique = 2;

	/** How to mark nodrop items */
	#[NCA\Setting\Options(options: [
		'Do not mark' => 0,
		'ND' => 1,
		'Nodrop' => 2,
	])]
	public int $whatbuffsShowNodrop = 0;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private ItemsController $itemsController;

	#[NCA\Inject]
	private BuffPerksController $buffPerksController;

	#[NCA\Inject]
	private SkillsController $skillsController;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/item_buffs.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/item_types.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/buffs.csv');
	}

	/** Show a list of attributes and skills that are being buffed */
	#[NCA\HandlesCommand('whatbuffs')]
	#[NCA\HandlesCommand('whatbuffsfroob')]
	public function whatbuffsCommand(CmdContext $context): void {
		$command = explode(' ', $context->message)[0];
		$froobFriendly = strtolower($command) === 'whatbuffsfroob';
		$this->showSkillChoice($context, $froobFriendly);
	}

	public function showSkillChoice(CommandReply $sendto, bool $froobFriendly): void {
		$command = 'whatbuffs' . ($froobFriendly ? 'froob' : '');
		$suffix = $froobFriendly ? 'Froob' : '';
		$blob = "<header2>Choose a skill<end>\n";

		/** @var Collection<int,Skill> */
		$skills = $this->db->table(ItemBuff::getTable())
			->select('attribute_id')
			->distinct()
			->pluckInts('attribute_id')
			->map(Skill::tryFrom(...))
			->filter();
		$skills = $skills->sortBy(static fn (Skill $s1): string => $s1->fullName());
		foreach ($skills as $skill) {
			$blob .= '<tab>' . Text::makeChatcmd($skill->fullName(), "/tell <myname> {$command} {$skill->fullName()}") . "\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = Text::makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	/** Search for buff items for a slot or skill/attribute */
	#[
		NCA\HandlesCommand('whatbuffs'),
		NCA\HandlesCommand('whatbuffsfroob')
	]
	public function whatbuffsOneWordCommand(CmdContext $context, #[NCA\WordStr] string $search): void {
		$command = explode(' ', $context->message)[0];
		$froobFriendly = strtolower($command) === 'whatbuffsfroob';
		$type = ucfirst(strtolower($this->resolveLocationAlias($search)));

		if ($this->verifySlot($type)) {
			$this->showSkillsBuffingType($type, $froobFriendly, $command, $context);
			return;
		}
		$this->handleOtherComandline($froobFriendly, $context, $search);
	}

	public function showSkillsBuffingType(string $type, bool $froobFriendly, string $command, CommandReply $sendto): void {
		if (!$this->verifySlot($type)) {
			$msg = "Could not find any items of type <highlight>{$type}<end>.";
			$sendto->reply($msg);
			return;
		}
		if ($type === 'Nanoprogram') {
			$query = $this->db->table(Buff::getTable(), 'b');
			$query
				->join(ItemBuff::getTable(as: 'ib'), 'ib.item_id', '=', 'b.id')
				->where(static function (QueryBuilder $query): void {
					$query->whereIn(
						'ib.attribute_id',
						[
							Skill::SkillLockModifier->value,
							Skill::AddNanoCost->value,
						]
					)->orWhere('ib.amount', '>', 0);
				})
				->groupBy('ib.attribute_id')
				->havingRaw($query->rawFunc('COUNT', 1) . ' > 0')
				->select([
					'ib.attribute_id AS skill',
					$query->raw($query->rawFunc('COUNT', 1, 'num')),
				]);
			if ($froobFriendly) {
				$query->where('b.froob_friendly', '=', true);
			}

			$data = $query->get()->map(static function (\stdClass $data): ?SkillBuffItemCount {
				$skill = Skill::tryFrom($data->skill);
				if (!isset($skill)) {
					return null;
				}
				return new SkillBuffItemCount(
					skill: $skill,
					num: (int)$data->num,
				);
			})->filter();
		} elseif ($type === 'Perk') {
			if ($froobFriendly) {
				$sendto->reply("Froobs don't have perks.");
				return;
			}
			$perkBuffs = $this->buffPerksController->perks->reduce(
				static function (Collection $result, Perk $perk): Collection {
					$skills = [];
					foreach ($perk->levels as $perkLevel) {
						foreach ($perkLevel->buffs as $skillId => $amount) {
							$negativeIsGood = Skill::tryFrom($skillId)?->negativeIsGood() ?? false;
							if ($negativeIsGood ? $amount < 0 : $amount > 0) {
								$skills[$skillId] = true;
							}
						}
					}
					foreach ($skills as $skillId => $true) {
						// @phpstan-ignore-next-line
						$result->put($skillId, $result->get($skillId, 0)+1);
					}
					return $result;
				},
				new Collection()
			);

			$data = $perkBuffs->map(static function (int $buff, int $skillId): ?SkillBuffItemCount {
				if (($skill = Skill::tryFrom($skillId)) === null) {
					return null;
				}
				$result = new SkillBuffItemCount(
					skill: $skill,
					num: $buff,
				);
				return $result;
			})->filter();
		} else {
			$query = $this->db->table(AODBEntry::getTable(), 'i');
			$query
				->join(ItemType::getTable(as: 'it'), 'it.item_id', '=', 'i.highid')
				->join(ItemBuff::getTable(as: 'ib'), 'ib.item_id', '=', 'i.highid')
				->where('it.item_type', '=', $type)
				->whereNotIn('i.name', ['Brad Test Nano'])
				->groupBy('ib.attribute_id')
				->havingRaw($query->rawFunc('COUNT', 1) . ' > 0')
				->select([
					'ib.attribute_id AS skill',
					$query->raw($query->rawFunc('COUNT', 1, 'num')),
				]);
			if ($froobFriendly) {
				$query->where('i.froob_friendly', '=', true);
			}
			if ($this->itemsController->onlyItemsInGame) {
				$query->where('i.in_game', '=', true);
			}

			$data = $query->get()->map(static function (\stdClass $data): ?SkillBuffItemCount {
				$skill = Skill::tryFrom($data->skill);
				if (!isset($skill)) {
					return null;
				}
				return new SkillBuffItemCount(
					skill: $skill,
					num: (int)$data->num,
				);
			})->filter();
		}

		/** @var Collection<int,SkillBuffItemCount> $data */
		$sorted = $data->sortBy(static fn (SkillBuffItemCount $b): string => $b->skill->fullName());

		$blob = "<header2>Choose the skill to buff<end>\n";
		foreach ($sorted as $row) {
			$blob .= '<tab>'.
				Text::makeChatcmd(
					$row->skill->fullName(),
					"/tell <myname> {$command} {$type} {$row->skill->fullName()}"
				).
				" ({$row->num})\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$suffix = $froobFriendly ? 'Froob' : '';
		$msg = Text::makeBlob("WhatBuffs{$suffix} {$type} - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	/** Search for a slot and or skill/attribute to buff */
	#[NCA\HandlesCommand('whatbuffs')]
	#[NCA\HandlesCommand('whatbuffsfroob')]
	#[NCA\Help\Example('<symbol>whatbuffs cl nanoprogram')]
	#[NCA\Help\Example('<symbol>whatbuffs legs agility')]
	public function whatbuffs5Command(CmdContext $context, string $search): void {
		$command = explode(' ', $context->message)[0];
		$froobFriendly = strtolower($command) === 'whatbuffsfroob';
		$this->handleOtherComandline($froobFriendly, $context, $search);
	}

	public function createPerkFilter(Skill $skill): Closure {
		return static function (Perk $perk, string $perkName) use ($skill): bool {
			foreach ($perk->levels as $level => $perkLevel) {
				if (!isset($perkLevel->buffs[$skill->value])) {
					continue;
				}
				$matches = $skill->negativeIsGood()
					? $perkLevel->buffs[$skill->value] < 0
					: $perkLevel->buffs[$skill->value] > 0;
				if ($matches) {
					return true;
				}
			}
			return false;
		};
	}

	public function handleOtherComandline(bool $froobFriendly, CmdContext $context, string $search): void {
		$tokens = explode(' ', $search);
		$skillSearch = Skill::tryByName($search, true);
		if (isset($skillSearch)) {
			$tokens = [$search];
		}
		$firstType = ucfirst(strtolower($this->resolveLocationAlias($tokens[0])));
		$lastType = ucfirst(strtolower($this->resolveLocationAlias($tokens[count($tokens) - 1])));

		if ($this->verifySlot($firstType) && !preg_match("/^smt\.?$/i", $tokens[1]??'')) {
			array_shift($tokens);
			$msg = $this->showSearchResults($firstType, implode(' ', $tokens), $froobFriendly);
			$context->reply($msg);
			return;
		} elseif ($this->verifySlot($lastType)) {
			array_pop($tokens);
			$msg = $this->showSearchResults($lastType, implode(' ', $tokens), $froobFriendly);
			$context->reply($msg);
			return;
		}
		$skill = $search;
		$command = 'whatbuffs' . ($froobFriendly ? 'froob' : '');
		$suffix = $froobFriendly ? 'Froob' : '';

		$skills = Skill::getMatching($skill);
		$count = count($skills);

		$blob = '';
		if ($count === 0) {
			$msg = "Could not find skill <highlight>{$skill}<end>.";
			$context->reply($msg);
			return;
		}
		if ($count > 1) {
			$blob .= "<header2>Choose a skill<end>\n";
			foreach ($skills as $row) {
				$blob .= '<tab>' . Text::makeChatcmd($row->fullName(), "/tell <myname> {$command} {$row->fullName()}") . "\n";
			}
			$blob .= "\nItem Extraction Info provided by AOIA+";
			$msg = Text::makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
			$context->reply($msg);
			return;
		}
		$skill = $skills[0];
		$itemQuery = $this->db->table(AODBEntry::getTable(), 'i');
		$itemQuery
			->join(ItemType::getTable(as: 'it'), 'it.item_id', '=', 'i.highid')
			->join(ItemBuff::getTable(as: 'ib'), 'ib.item_id', '=', 'i.highid')
			->where('ib.attribute_id', '=', $skill->value)
			->where('ib.amount', $skill->negativeIsGood() ? '<' : '>', 0)
			->groupBy('i.name', 'it.item_type', 'i.lowql', 'i.highql', 'ib.amount')
			->select('it.item_type');
		$nanoQuery = $this->db->table(Buff::getTable(), 'b');
		$nanoQuery
			->join(ItemBuff::getTable(as: 'ib'), 'ib.item_id', '=', 'b.id')
			->where('ib.attribute_id', '=', $skill->value)
			->where('ib.amount', $skill->negativeIsGood() ? '<' : '>', 0)
			->select(
				$nanoQuery->raw(
					$nanoQuery->grammar->quoteString('Nanoprogram').
					' AS ' . $nanoQuery->grammar->wrap('item_type')
				)
			);
		if ($froobFriendly) {
			$itemQuery->where('i.froob_friendly', '=', true);
			$nanoQuery->where('b.froob_friendly', '=', true);
		}
		if ($this->itemsController->onlyItemsInGame) {
			$itemQuery->where('i.in_game', '=', true);
		}
		$innerQuery = $itemQuery
			->unionAll($nanoQuery);
		$query = $this->db->fromSub($innerQuery, 'foo');
		$query
			->groupBy('foo.item_type')
			->orderBy('foo.item_type')
			->select(['foo.item_type', $query->raw($query->rawFunc('COUNT', '*', 'num'))]);
		$data = $query->asObj(SkillBuffTypeCount::class);
		if (!$froobFriendly) {
			$numPerks = $this->buffPerksController->perks->filter(
				$this->createPerkFilter($skill)
			)->count();
			$perkCount = new SkillBuffTypeCount(
				item_type: 'Perk',
				num: $numPerks,
			);
			$data = $data->push($perkCount)->sortBy('item_type');
		}
		if (count($data) === 0) {
			$msg = "There are currently no known items or nanos buffing <highlight>{$skill->fullName()}<end>";
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Choose buff type<end>\n";
		foreach ($data as $row) {
			$blob .= '<tab>' . Text::makeChatcmd(ucfirst($row->item_type), "/tell <myname> {$command} {$row->item_type} {$skill->fullName()}") . " ({$row->num})\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = Text::makeBlob("WhatBuffs{$suffix} {$skill->fullName()} - Choose Type", $blob);
		$context->reply($msg);
	}

	/** Gives a blob with all items buffing $skill in slot $category */
	public function getSearchResults(string $category, Skill $skill, bool $froobFriendly): string {
		$suffix = $froobFriendly ? 'Froob' : '';
		$addNotInGameNotice = false;
		if ($category === 'Nanoprogram') {
			$query = $this->db->table(Buff::getTable(), 'b');
			$query
				->join(ItemBuff::getTable(as: 'ib'), 'ib.item_id', 'b.id')
				->leftJoin(AODBEntry::getTable(as: 'a'), 'a.lowid', 'b.use_id')
				->where('ib.attribute_id', $skill->value)
				->where(static function (QueryBuilder $query): void {
					$query->whereIn('ib.attribute_id', [
						Skill::SkillLockModifier->value,
						Skill::AddNanoCost->value,
					])
					->orWhere('ib.amount', '>', 0);
				})->whereNotIn('b.name', [
					'Ineptitude Transfer',
					'Accumulated Interest',
					'Unforgiven Debts',
					'Payment Plan',
				])->orderByDesc('ib.amount')
				->orderBy('b.name')
				->select([
					'b.*', 'ib.amount', 'a.lowid', 'a.highid',
					'a.lowql', 'a.name AS use_name',
				]);
			if ($froobFriendly) {
				$query->where('b.froob_friendly', true);
			}

			$data = $query->asObj(NanoBuffSearchResult::class);
			if ($data->isNotEmpty() && $data->lastOrFail()->amount < 0) {
				$data = $data->reverse();
			}
			$result = $this->formatBuffs($data, $skill);
		} elseif ($category === 'Perk') {
			if ($froobFriendly) {
				return "Froobs don't have perks.";
			}

			$perks = $this->buffPerksController->perks->filter(
				$this->createPerkFilter($skill)
			);
			$data = [];
			$perks->each(static function (Perk $perk, string $perkName) use (&$data, $skill): void {
				foreach ($perk->levels as $perkLevel) {
					if (!isset($perkLevel->buffs[$skill->value])) {
						continue;
					}
					$result = new PerkBuffSearchResult(
						name: $perk->name,
						amount: $perkLevel->buffs[$skill->value],
						expansion: $perk->expansion,
						perk_level: $perkLevel->perk_level,
						profs: implode(',', $perkLevel->professions),
					);
					$data []= $result;
				}
			});
			$data = $this->generatePerkBufflist($data);
			$result = $this->formatPerkBuffs($data, $skill);
		} else {
			$query = $this->db->table(AODBEntry::getTable(), 'a');
			$query
				->join(ItemType::getTable(as: 'i'), 'i.item_id', 'a.highid')
				->join(ItemBuff::getTable(as: 'b'), 'b.item_id', 'a.highid')
				->leftJoin(ItemBuff::getTable(as: 'b2'), 'b2.item_id', 'a.lowid')
				->where('b.attribute_id', $skill->value)
				->where('b2.attribute_id', $skill->value)
				->where('i.item_type', $category)
				->where('b.amount', $skill->negativeIsGood() ? '<' : '>', 0)
				->groupBy([
					'a.name', 'a.lowql', 'a.highql', 'b.amount', 'b2.amount', 'a.lowid',
					'a.highid', 'a.icon', 'a.froob_friendly', 'a.slot', 'a.flags',
					'a.in_game', 'a.type',
				])->orderByDesc($query->raw($query->colFunc('ABS', 'b.amount')))
				->orderByDesc('name')
				->select([
					'a.*', 'b.amount', 'b2.amount AS low_amount',
				]);
			if ($froobFriendly) {
				$query->where('a.froob_friendly', true);
			}
			if ($this->itemsController->onlyItemsInGame) {
				$query->where('a.in_game', true);
			}

			$data = $query->asObj(ItemBuffSearchResult::class);
			$specialsById = $this->skillsController->getWeaponAttributes(
				aoid: $data->pluck('highid')->toList()
			)->keyBy('id');
			$data->each(static function (ItemBuffSearchResult $item) use ($specialsById): void {
				if (($specials = $specialsById->get($item->highid)) === null) {
					$item->multi_m = null;
					$item->multi_r = null;
					return;
				}
				$item->multi_m = $specials->multi_m;
				$item->multi_r = $specials->multi_r;
			});
			if ($data->isNotEmpty() && $data->lastOrFail()->amount < 0) {
				$data = $data->reverse();
			}
			$result = $this->formatItems($data, $skill, $category);
			if ($data->first(static fn (ItemBuffSearchResult $i): bool => !$i->in_game)) {
				$addNotInGameNotice = true;
			}
		}

		if ($result->numItems === 0) {
			$msg = "No items found of type <highlight>{$category}<end> that buff <highlight>{$skill->fullName()}<end>.";
		} else {
			if ($addNotInGameNotice) {
				$result->blob .= "\n<red>(!)<end> means: This item is GM/ARK-only, not in the game, or unavailable";
			}
			$result->blob .= "\nItem Extraction Info provided by AOIA+";
			$msg = Text::makeBlob("WhatBuffs{$suffix} - {$category} {$skill->fullName()} ({$result->numItems})", $result->blob);
		}
		return $msg;
	}

	/** Check if a slot (fingers, chest) exists */
	public function verifySlot(string $type): bool {
		return $this->db->table(ItemType::getTable())
			->where('item_type', $type)
			->exists() || strtolower($type) === 'perk';
	}

	public function showItemLink(AOItemSpec $item, int $ql): string {
		return $item->getLink($ql);
	}

	/**
	 * Format a list of item buff search results
	 *
	 * @param iterable<array-key,ItemBuffSearchResult> $items The items that matched the search
	 */
	public function formatItems(iterable $items, Skill $skill, string $category): RenderedList {
		$showUniques = $this->whatbuffsShowUnique;
		$showNodrops = $this->whatbuffsShowNodrop;
		$blob = '<header2>' . ucfirst($this->locationToItem($category)) . " that buff {$skill->fullName()}<end>\n";
		$maxBuff = 0;
		$itemMapping = [];
		$maxQL = [];
		$maxAmount = [];
		$items = collect($items);
		foreach ($items as $item) {
			if ($item->amount === $item->low_amount) {
				$item->highql = $item->lowql;
			}
			// Some items are not in game with the maximum possible QL
			// Replace the shown QL with the maximum possible QL
			$maxQL[$item->lowid] = $item->highql;
			$maxAmount[$item->lowid] = $item->amount;
			if (
				$item->highql > 250 && (
					str_contains($item->name, ' Filigree Ring set with a ')
					|| strncmp($item->name, 'Universal Advantage - ', 22) === 0
				)
			) {
				$item->amount = Util::interpolate($item->lowql, $item->highql, $item->low_amount??$item->amount, $item->amount, 250);
				$item->highql = 250;
			}
			$maxBuff = max($maxBuff, abs($item->amount));
			if ($item->lowid === $item->highid) {
				$itemMapping[$item->lowid] = $item;
			}
		}
		$multiplier = 1;
		if ($skill->negativeIsGood()) {
			$multiplier = -1;
		}
		$items = $items->sort(
			static function (ItemBuffSearchResult $a, ItemBuffSearchResult $b) use ($multiplier): int {
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
			$prefix = '<tab>' . $sign.Text::alignNumber(abs($item->amount), $maxDigits, 'highlight');
			$blob .= $prefix . $skill->getUnit() . '  ';
			$blob .= $this->getSlotPrefix($item, $category);
			$blob .= $this->showItemLink($item, $item->highql);
			if (!$item->in_game) {
				$blob .= ' <red>(!)<end>';
			}
			if ($item->amount > ($item->low_amount??0)) {
				$blob .= " ({$item->low_amount} - {$item->amount})";
				if ($this->commandManager->cmdEnabled('bestql')) {
					$link = $item->getLink(ql: 0);
					$blob .= ' ' . Text::makeChatcmd(
						'Breakpoints',
						"/tell <myname> bestql {$item->lowql} {$item->low_amount} ".
							$maxQL[$item->lowid] . ' ' . $maxAmount[$item->lowid].
							" {$link}"
					);
				}
			}
			if ($item->flags->has(ItemFlag::UNIQUE) && $showUniques) {
				$blob .= $showUniques === 1 ? ' U' : ' Unique';
			}
			if ($item->flags->has(ItemFlag::NO_DROP) && $showNodrops) {
				$blob .= $showNodrops === 1 ? ' ND' : ' Nodrop';
			}
			$blob .= "\n";
		}

		$count = count($items);
		return new RenderedList(numItems: $count, blob: $blob);
	}

	/**
	 * @param iterable<NanoBuffSearchResult> $items
	 *
	 * @return Generator<array-key,NanoBuffSearchResult>
	 */
	public function groupDrainsAndWrangles(iterable $items): Generator {
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
				yield $item;
			}
		}
	}

	/** @param iterable<PerkBuffSearchResult> $perks */
	public function formatPerkBuffs(iterable $perks, Skill $skill): RenderedList {
		$blob = "<header2>Perks that buff {$skill->fullName()}<end>\n";
		$maxBuff = $numPerks = 0;
		foreach ($perks as $perk) {
			$maxBuff = max($maxBuff, abs($perk->amount));
			$numPerks++;
		}
		$maxDigits = strlen((string)$maxBuff);
		foreach ($perks as $perk) {
			$color = $perk->expansion === 'ai' ? '<green>' : '<highlight>';
			if (substr_count($perk->profs, ',') < 13) {
				$perk->profs = implode(
					"<end>, {$color}",
					array_map(
						static fn (string $long): string => Profession::byName($long)->short(),
						explode(',', $perk->profs)
					)
				);
			} else {
				$perk->profs = 'All';
			}
			$sign = ($perk->amount > 0) ? '+' : '-';
			$prefix = "<tab>{$sign}" . Text::alignNumber(abs($perk->amount), $maxDigits, 'highlight');
			$blob .= $prefix . "{$skill->getUnit()}  {$perk->name} ({$color}{$perk->profs}<end>)\n";
		}

		return new RenderedList(numItems: $numPerks, blob: $blob);
	}

	/** @param iterable<array-key,NanoBuffSearchResult> $items */
	public function formatBuffs(iterable $items, Skill $skill): RenderedList {
		$items = collect($items)->filter(
			static function (NanoBuffSearchResult $nano): bool {
				return !preg_match("/^Composite .+ Expertise \(\d hours\)$/", $nano->name);
			}
		)->values();
		$blob = "<header2>Nanoprograms that buff {$skill->fullName()}<end>\n";
		$maxBuff = 0;
		foreach ($items as $item) {
			$maxBuff = max($maxBuff, abs($item->amount));
		}
		$maxDigits = strlen((string)$maxBuff);
		$items = $this->groupDrainsAndWrangles($items);
		$numItems = 0;
		foreach ($items as $item) {
			$numItems++;
			if ($item->ncu === 999) {
				$item->ncu = 0;
			}
			$prefix = '<tab>' . Text::alignNumber($item->amount, $maxDigits, 'highlight');
			$blob .= "{$prefix}{$skill->getUnit()}  <a href='itemid://53019/{$item->id}'>{$item->name}</a> ";
			if (isset($item->low_ncu, $item->low_amount)) {
				$blob .= "({$item->low_ncu} NCU (<highlight>{$item->low_amount}<end>) - {$item->ncu} NCU (<highlight>{$item->amount}<end>))";
			} else {
				$blob .= "({$item->ncu} NCU)";
			}
			if (isset($item->lowid) && $item->lowid > 0 && isset($item->lowql)) {
				$blob .= ' (from ' . Text::makeItem($item->lowid, $item->highid??$item->lowid, $item->lowql, $item->use_name??'') . ')';
			}
			$blob .= "\n";
		}

		return new RenderedList(numItems: $numItems, blob: $blob);
	}

	/** Show what buffs $skillName in slot $category */
	public function showSearchResults(string $category, string $skillName, bool $froobFriendly): string {
		$category = ucfirst(strtolower($category));

		$skills = Skill::getMatching($skillName);
		$count = count($skills);

		if ($count === 0) {
			$msg = "Could not find any skills matching <highlight>{$skillName}<end>.";
		} elseif ($count === 1) {
			$skill = $skills[0];
			$msg = $this->getSearchResults($category, $skill, $froobFriendly);
		} else {
			$blob = '';
			$command = 'whatbuffs' . ($froobFriendly ? 'froob' : '');
			$suffix = $froobFriendly ? 'Froob' : '';
			foreach ($skills as $skill) {
				$blob .= Text::makeChatcmd(ucfirst($skill->fullName()), "/tell <myname> {$command} {$category} {$skill->fullName()}") . "\n";
			}
			$msg = Text::makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
		}

		return $msg;
	}

	/**
	 * @param iterable<PerkBuffSearchResult> $data
	 *
	 * @return Collection<array-key,PerkBuffSearchResult>
	 */
	private function generatePerkBufflist(iterable $data): Collection {
		/** @var array<string,PerkBuffSearchResult> */
		$result = [];
		foreach ($data as $perk) {
			if (!isset($perk->name)) {
				continue;
			}
			if (!isset($result[$perk->name])) {
				$result[$perk->name] = $perk;
			} else {
				$result[$perk->name]->amount += $perk->amount;
			}
			$profs = explode(',', $perk->profs);
			foreach ($profs as $prof) {
				$result[$perk->name]->profMax[$prof] += $perk->amount;
			}
		}

		/** @var Collection<array-key,PerkBuffSearchResult> */
		$newData = new Collection();
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
				$obj->profs = implode(',', $profs);
				$obj->profMax = [];
				$newData->push($obj);
			}
		}
		return $newData->sort(
			static function (PerkBuffSearchResult $p1, PerkBuffSearchResult $p2): int {
				if ($p2->amount === $p1->amount) {
					return strcmp($p1->name??'', $p2->name??'');
				}
				return $p2->amount <=> $p1->amount;
			}
		);
	}

	private function getSlotPrefix(ItemBuffSearchResult $item, string $category): string {
		if (!($item->slot instanceof EnumBitfield)) {
			return '';
		}
		$markSetting = $this->whatbuffsDisplay;
		$result = '';
		if ($item->multi_m !== null || $item->multi_r !== null) {
			if ($item->slot->hasAll(CarrySlot::LeftHand, CarrySlot::RightHand)) {
				return '2x ';
			} elseif ($item->slot->has(CarrySlot::LeftHand)) {
				$result = 'L-Hand ';
			} else {
				$result = 'R-Hand ';
			}
		} elseif ($category === 'Arms') {
			if ($item->slot->hasAll(WearSlot::LeftArm, WearSlot::RightArm)) {
			} elseif ($item->slot->has(WearSlot::LeftArm)) {
				$result = 'L-Arm ';
			} elseif ($item->slot->has(WearSlot::RightArm)) {
				$result = 'R-Arm ';
			}
		} elseif ($category === 'Wrists') {
			if ($item->slot->hasAll(WearSlot::LeftWrist, WearSlot::RightWrist)) {
			} elseif ($item->slot->has(WearSlot::LeftWrist)) {
				$result = 'L-Wrist ';
			} elseif ($item->slot->has(WearSlot::RightWrist)) {
				$result = 'R-Wrist ';
			}
		} elseif ($category === 'Fingers') {
			if ($item->slot->hasAll(WearSlot::LeftFinger, WearSlot::RightFinger)) {
			} elseif ($item->slot->has(WearSlot::LeftFinger)) {
				$result = 'L-Finger ';
			} elseif ($item->slot->has(WearSlot::RightFinger)) {
				$result = 'R-Finger ';
			}
		} elseif ($category === 'Shoulders') {
			if ($item->slot->hasAll(WearSlot::LeftShoulder, WearSlot::RightShoulder)) {
			} elseif ($item->slot->has(WearSlot::LeftShoulder)) {
				$result = 'L-Shoulder ';
			} elseif ($item->slot->has(WearSlot::RightShoulder)) {
				$result = 'R-Shoulder ';
			}
		}
		if ($markSetting === 0) {
			return '';
		}
		if ($markSetting === 1 && strlen($result) > 1) {
			return substr($result, 0, 1) . ' ';
		}
		return $result;
	}

	/** Convert a location (arms) to item type (sleeves) */
	private function locationToItem(string $location): string {
		$location = strtolower($location);
		$map = [
			'arms' => 'sleeves',
			'back' => 'back-items',
			'deck' => 'deck-items',
			'feet' => 'boots',
			'fingers' => 'rings',
			'hands' => 'gloves',
			'head' => 'helmets',
			'hud' => 'HUD-items',
			'legs' => 'pants',
			'neck' => 'neck-items',
			'wrists' => 'wrist items',
			'use' => 'usable items',
		];
		if (isset($map[$location])) {
			return $map[$location];
		}
		return rtrim($location, 's') . 's';
	}

	/** Resolve aliases for locations like arms and sleeves  into proper locations */
	private function resolveLocationAlias(string $location): string {
		$location = strtolower($location);
		$map = [
			'arm' => 'arms',
			'sleeve' => 'arms',
			'sleeves' => 'arms',
			'ncu' => 'deck',
			'contracts' => 'contract',
			'belt' => 'deck',
			'boots' => 'feet',
			'foot' => 'feet',
			'ring' => 'fingers',
			'rings' => 'fingers',
			'finger' => 'fingers',
			'gloves' => 'hands',
			'glove' => 'hands',
			'gauntlets' => 'hands',
			'gauntlet' => 'hands',
			'hand' => 'hands',
			'helmets' => 'head',
			'helmet' => 'head',
			'pants' => 'legs',
			'pant' => 'legs',
			'perks' => 'perk',
			'weapons' => 'weapon',
			'shoulder' => 'shoulders',
			'wrist' => 'wrists',
		];
		return $map[$location] ?? $location;
	}
}
