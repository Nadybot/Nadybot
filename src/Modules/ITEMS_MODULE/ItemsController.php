<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use function Safe\preg_split;

use BackedEnum;
use Illuminate\Support\Collection;

use Nadybot\Core\Types\{Bitfield};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Safe,
	SettingManager,
	Text,
};
use Psr\Log\LoggerInterface;

#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Items'),
	NCA\DefineCommand(
		command: 'items',
		accessLevel: 'guest',
		description: 'Searches for an item using the default items db',
		alias: 'i'
	),
	NCA\DefineCommand(
		command: 'itemid',
		accessLevel: 'guest',
		description: 'Searches for an item by id',
	),
	NCA\DefineCommand(
		command: 'id',
		accessLevel: 'guest',
		description: 'Searches for an itemid by name',
	),
]
class ItemsController extends ModuleInstance {
	/** Number of items shown on the list */
	#[NCA\Setting\Number(options: [30, 40, 50, 60])]
	public int $maxitems = 40;

	/** Exclude GM-only items and items which are not in the game */
	#[NCA\Setting\Boolean]
	public bool $onlyItemsInGame = true;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private Text $text;

	/** @var array<int,Skill> */
	private array $skills = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/aodb.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/item_groups.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/item_group_names.csv');

		$this->skills = $this->db->table(Skill::getTable())
			->asObj(Skill::class)
			->keyBy('id')
			->toArray();
	}

	/**
	 * Search for an item by name, optionally in a specific QL.
	 * You can also use '-&lt;search&gt;' to exclude those matching the term
	 * and prefix your search with * to include items not in game/gm-only.
	 */
	#[NCA\HandlesCommand('items')]
	#[NCA\Help\Example('<symbol>items first tier nano')]
	#[NCA\Help\Example('<symbol>items 133 first tier nano')]
	#[NCA\Help\Example('<symbol>items panther -ofab')]
	#[NCA\Help\Example('<symbol>items *ebony figurine')]
	public function itemsCommand(CmdContext $context, ?int $ql, string $search): void {
		$msg = $this->findItems($ql, $search);
		$context->reply($msg);
	}

	/** Show information about an item id */
	#[NCA\HandlesCommand('itemid')]
	#[NCA\Help\Example('<symbol>itemid 244718', 'Show Burden of Competence')]
	public function itemIdCommand(CmdContext $context, int $id): void {
		$row = $this->findById($id);
		if ($row === null) {
			$msg = "No item found with id <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		$blob = '';
		foreach (get_object_vars($row) as $key => $value) {
			if ($key === 'numExactMatches') {
				continue;
			}
			$key = str_replace('_', ' ', $key);
			if ($value instanceof BackedEnum) {
				$blob .= "{$key}: <highlight>{$value->name}<end>\n";
			} elseif ($value instanceof Bitfield) {
				$intValue = $value->toInt();
				if ($intValue === 0) {
					$blob .= "{$key}: <highlight>&lt;none&gt;<end>\n";
				} else {
					$blob .= "{$key}: <highlight>{$value}<end>\n";
				}
			} elseif ($key === 'icon' && is_int($value) && $value > 0) {
				$blob .= "{$key}: <highlight>{$value}<end> ({$row->getIcon()})\n";
			} else {
				$blob .= "{$key}: <highlight>" . (is_bool($value) ? ($value ? 'yes' : 'no') : ($value??'<empty>')) . "<end>\n";
			}
		}
		$ql = $row->highql;
		if ($row->lowid === $id) {
			$ql = $row->lowql;
		}
		$row = ItemSearchResult::fromItem($row->atQL($ql));
		$blob .= "\n" . $this->formatSearchResults([$row], null, true);
		$msg = Text::blobWrap(
			'Details about item ID ',
			$this->text->makeBlob((string)$id, $blob, "Details about item ID {$id}"),
			" ({$row->name})"
		);

		$context->reply($msg);
	}

	public function findById(int $id): ?AODBEntry {
		return $this->db->table(AODBEntry::getTable())
			->where('lowid', $id)
			->union(
				$this->db->table(AODBEntry::getTable())
					->where('highid', $id)
			)
			->limit(1)
			->asObj(AODBEntry::class)
			->first();
	}

	/**
	 * Get 1 or more items by their IDs
	 *
	 * @return Collection<int,AODBEntry>
	 */
	public function getByIDs(int ...$ids): Collection {
		return $this->db->table(AODBEntry::getTable())
			->whereIn('lowid', $ids)
			->union(
				$this->db->table(AODBEntry::getTable())
					->whereIn('highid', $ids)
			)
			->asObj(AODBEntry::class);
	}

	/**
	 * Get 1 or more items by their names
	 *
	 * @return Collection<int,AODBEntry>
	 */
	public function getByNames(string ...$names): Collection {
		return $this->db->table(AODBEntry::getTable())
			->whereIn('name', $names)
			->asObj(AODBEntry::class);
	}

	/**
	 * Get 1 or more items by a name search
	 *
	 * @return Collection<int,AODBEntry>
	 */
	public function getBySearch(string $search, ?int $ql=null): Collection {
		$query = $this->db->table(AODBEntry::getTable());
		$tmp = explode(' ', $search);
		$this->db->addWhereFromParams($query, $tmp, 'name');

		if ($ql !== null) {
			$query->where('a.lowql', '<=', $ql)
				->where('a.highql', '>=', $ql);
		}
		return $query->asObj(AODBEntry::class);
	}

	/** Search the item id of an item */
	#[NCA\HandlesCommand('id')]
	public function idCommand(CmdContext $context, string $search): void {
		$query = $this->db->table(AODBEntry::getTable(), 'a')
			->leftJoin(ItemGroup::getTable(as: 'g'), 'g.item_id', 'a.lowid')
			->leftJoin(ItemGroupName::getTable(as: 'gn'), 'g.group_id', 'gn.group_id')
			->orderByColFunc('COALESCE', ['gn.name', 'a.name'])
			->orderBy('a.lowql')
			->select('a.*')
			->limit($this->maxitems);
		$tmp = explode(' ', $search);
		$this->db->addWhereFromParams($query, $tmp, 'a.name');

		$items = $query->asObj(AODBEntry::class);
		if ($items->isEmpty()) {
			$context->reply("No items found matching <highlight>{$search}<end>.");
			return;
		}
		$blob = "<header2><u>Low ID    Low QL    High ID    High QL    Name                                         </u><end>\n";
		foreach ($items as $item) {
			$itemLinkLow = $item->getLink(ql: $item->lowql, text: (string)$item->lowid);
			$itemLinkHigh = $item->getLink(ql: $item->highql, text: (string)$item->highid);
			$blob .= str_replace((string)$item->lowid, $itemLinkLow, Text::alignNumber($item->lowid, 6)).
				'       ' . Text::alignNumber($item->lowql, 3).
				'     ' . (($item->highid === $item->lowid) ? '        ' : str_replace((string)$item->highid, $itemLinkHigh, Text::alignNumber($item->highid, 6))).
				'         ' . (($item->highid === $item->lowid) ? '         <black>|<end>' : Text::alignNumber($item->highql, 3) . '    ').
				$item->name . "\n";
		}
		if (count($items) >= $this->maxitems) {
			$blob .= "\n\n<highlight>*Results have been limited to the first " . count($items) . ' results.<end>';
		}
		$msg = $this->text->makeBlob("Items matching \"{$search}\" (" . count($items) . ')', $blob);
		$context->reply($msg);
	}

	/** @return string|list<string> */
	public function findItems(?int $ql, string $search): string|array {
		if (isset($ql)) {
			if ($ql < 1 || $ql > 500) {
				return 'QL must be between 1 and 500.';
			}
		}

		$search = htmlspecialchars_decode($search);
		$dontExclude = false;

		$search = Safe::pregReplace("/\s*\*\s*/", '', $search, 1, $numReplaces);
		$dontExclude = $numReplaces > 0;

		// local database
		$data = $this->findItemsFromLocal($search, $ql, $dontExclude);

		$aoiaPlusLink = Text::makeChatcmd('AOIA+', '/start https://sourceforge.net/projects/aoiaplus');
		$footer = 'QLs between <red>[<end>brackets<red>]<end> denote items matching your name search';
		if (count(array_filter($data, static fn (ItemSearchResult $i): bool => !$i->in_game))) {
			$footer .= "\n<red>(!)<end> means: This item is GM/ARK-only, not in the game, or unavailable";
		}
		$footer .= "\nItem DB rips created using the {$aoiaPlusLink} tool.";

		$msg = $this->createItemsBlob($data, $search, $ql, $this->settingManager->getString('aodb_db_version')??'unknown', $footer);

		return $msg;
	}

	/**
	 * Search for items in the local database
	 *
	 * @param string   $search The searchterm
	 * @param null|int $ql     The QL to return the results in
	 *
	 * @return list<ItemSearchResult>
	 */
	public function findItemsFromLocal(string $search, ?int $ql, bool $dontExclude=false): array {
		$innerQuery = $this->db->table(AODBEntry::getTable(), 'a')
			->leftJoin(ItemGroup::getTable(as: 'g'), 'g.item_id', 'a.lowid');
		$tmp = explode(' ', $search);
		$this->db->addWhereFromParams($innerQuery, $tmp, 'name');

		if ($ql !== null) {
			$innerQuery->where('a.lowql', '<=', $ql)
				->where('a.highql', '>=', $ql);
		}
		$innerQuery->groupByRaw($innerQuery->colFunc('COALESCE', ['g.group_id', 'a.lowid']))
			->groupBy(
				'a.lowid',
				'a.highid',
				'a.lowql',
				'a.highql',
				'a.name',
				'a.icon',
				'a.froob_friendly',
				'a.slot',
				'a.flags',
				'g.group_id',
				'a.in_game',
				'a.type'
			)->orderBy('a.name')
			->orderByDesc('a.highql')
			->limit($this->maxitems)
			->select(['a.*', 'g.group_id']);
		if ($this->onlyItemsInGame && !$dontExclude) {
			$innerQuery->where('a.in_game', true);
		}
		$query = $this->db->fromSub($innerQuery, 'foo')
			->leftJoin(ItemGroup::getTable(as: 'g'), 'foo.group_id', 'g.group_id')
			->leftJoin(ItemGroupName::getTable(as: 'n'), 'foo.group_id', 'n.group_id')
			->leftJoin(AODBEntry::getTable(as: 'a1'), 'g.item_id', 'a1.lowid')
			->leftJoin(AODBEntry::getTable(as: 'a2'), 'g.item_id', 'a2.highid')
			->orderBy('g.id');
		$query->selectRaw($query->colFunc('COALESCE', ['a2.name', 'a1.name', 'foo.name'], 'name'))
			->addSelect('n.name AS group_name')
			->addSelect('foo.icon')
			->addSelect('foo.in_game')
			->addSelect('foo.slot')
			->addSelect('foo.type')
			->addSelect('g.group_id')
			->addSelect('foo.flags')
			->selectRaw($query->colFunc('COALESCE', ['a1.lowid', 'a2.lowid', 'foo.lowid'], 'lowid'))
			->selectRaw($query->colFunc('COALESCE', ['a1.highid', 'a2.highid', 'foo.highid'], 'highid'))
			->selectRaw($query->colFunc('COALESCE', ['a1.lowql', 'a2.highql', 'foo.highql'], 'ql'))
			->selectRaw($query->colFunc('COALESCE', ['a1.lowql', 'a2.lowql', 'foo.lowql'], 'lowql'))
			->selectRaw($query->colFunc('COALESCE', ['a1.highql', 'a2.highql', 'foo.highql'], 'highql'));
		$data = $query->asObj(ItemSearchResult::class);
		$data = $data->filter(static function (ItemSearchResult $item): bool {
			static $found = [];
			if (isset($found[$item->lowid . '-' . $item->highid . ':' . $item->ql])) {
				return false;
			}
			$found[$item->lowid . '-' . $item->highid . ':' . $item->ql] = true;
			return true;
		});
		$groups = $data->groupBy('group_id');
		$groupsProcessed = [];

		/** @var Collection<int,ItemSearchResult> */
		$result = new Collection();
		while (count($result) < $this->maxitems && $data->count() > 0) {
			/** @var ItemSearchResult */
			$nextItem = $data->shift(1);
			if (!isset($nextItem->group_id) || !isset($groupsProcessed[$nextItem->group_id])) {
				if (isset($nextItem->group_id)) {
					/** @psalm-suppress PossiblyNullReference */
					$result->push(...$groups->get($nextItem->group_id)->toArray());
					$groupsProcessed[$nextItem->group_id] = true;
				} else {
					$result->push($nextItem);
				}
			}
		}
		return $result->toList();
	}

	/**
	 * @param iterable<array-key,ItemSearchResult> $data
	 *
	 * @return string|list<string>
	 */
	public function createItemsBlob(iterable $data, string $search, ?int $ql, string $version, string $footer, mixed $elapsed=null): string|array {
		$data = collect($data);
		$numItems = count($data);
		$groups = $data->map(static fn (ItemSearchResult $row): ?int => $row->group_id)
			->filter()->unique()->count()
			+
			$data->whereNull('group_id')->count();

		if ($numItems === 0) {
			if ($ql !== null) {
				$msg = "No QL <highlight>{$ql}<end> items found matching <highlight>{$search}<end>.";
			} else {
				$msg = "No items found matching <highlight>{$search}<end>.";
			}
			return $msg;
		} elseif ($groups < 4) {
			return trim($this->formatSearchResults($data, $ql, false, $search));
		}
		$blob = "Version: <highlight>{$version}<end>\n";
		if ($ql !== null) {
			$blob .= "Search: <highlight>QL {$ql} {$search}<end>\n";
		} else {
			$blob .= "Search: <highlight>{$search}<end>\n";
		}
		if ($elapsed) {
			$blob .= 'Time: <highlight>' . round($elapsed, 2) . "s<end>\n";
		}
		$blob .= "\n";
		$blob .= $this->formatSearchResults($data, $ql, true, $search);
		if ($numItems >= $this->maxitems) {
			$blob .= "\n\n<highlight>*Results have been limited to the first {$numItems} results.<end>";
		}
		$blob .= "\n\n" . $footer;
		$link = $this->text->makeBlob("Item Search Results ({$numItems})", $blob);

		return $link;
	}

	/** @param iterable<int,ItemSearchResult> $data */
	public function formatSearchResults(iterable $data, ?int $ql, bool $showImages, ?string $search=null): string {
		$list = '';
		$oldGroup = null;
		$data = collect($data);
		for ($itemNum = 0; $itemNum < count($data); $itemNum++) {
			$row = $data[$itemNum];
			$origName = $row->name;
			$newGroup = false;
			if (!isset($row->group_id) && isset($ql) && $ql !== $row->ql) {
				continue;
			}
			if (!isset($row->group_id) || $row->group_id !== $oldGroup) {
				$lastQL = null;
				$newGroup = true;
				// If this is a group of items, name them by their longest common name
				if (isset($nameMatches)) {
					if (substr($list, -2, 2) === ', ') {
						$list = substr($list, 0, strlen($list) - 2) . '<red>]<end>, ';
					} else {
						$list .= '<red>]<end>';
					}
					unset($nameMatches);
				}
				if (isset($row->group_id)) {
					$inGame = false;
					$itemNames = [];
					for ($j=$itemNum; $j < count($data); $j++) {
						if ($data[$j]->group_id === $row->group_id) {
							$itemNames []= $data[$j]->name;
							$inGame = $inGame || $data[$j]->in_game;
						} else {
							break;
						}
					}
					if (!isset($row->group_name)) {
						$row->name = $this->getLongestCommonStringOfWords($itemNames);
					} else {
						$row->name = $row->group_name;
					}
					if (!$inGame) {
						$row->name .= ' <red>(!)<end>';
					}
				}
				if ($list !== '') {
					$list .= "\n";
				}
				if ($showImages) {
					$list .= "\n<pagebreak>" . Text::makeImage($row->icon) . "\n";
				}
				if (isset($row->group_id)) {
					$list .= $row->name;
					if ($showImages) {
						$list .= "\n";
					} else {
						$list .= ' - ';
					}
				}
			}
			$oldGroup = $row->group_id ?? null;
			if (!isset($row->group_id)) {
				$list .= $row->getLink();
				if (!$row->in_game) {
					$list .= ' <red>(!)<end>';
				}
				$list .= " (QL {$row->ql})";
			} else {
				if ($newGroup === true) {
					$list .= 'QL ';
				} elseif (isset($lastQL) && $lastQL === $row->ql) {
					continue;
				} else {
					$list .= ', ';
				}
				if (isset($search) && $this->itemNameMatchesSearch($origName, $search)) {
					if (!isset($nameMatches)) {
						$list .= '<red>[<end>';
						$nameMatches = true;
					}
				} elseif (isset($nameMatches)) {
					if (substr($list, -2, 2) === ', ') {
						$list = substr($list, 0, strlen($list) - 2) . '<red>]<end>, ';
					} else {
						$list .= '<red>]<end>';
					}
					unset($nameMatches);
				}
				$item = $row->getLink(text: (string)$row->ql);
				if ($ql === $row->ql) {
					$list .= "<yellow>[<end>{$item}<yellow>]<end>";
				} elseif (isset($ql) && $ql > $row->lowql && $ql < $row->highql && $ql < $row->ql) {
					$list .= '<yellow>[<end>' . $row->getLink(ql: $ql, text: (string)$ql) . '<yellow>]<end>';
					$list .= ", {$item}";
				} elseif (
					isset($ql)
					&& $ql > $row->lowql && $ql < $row->highql && $ql > $row->ql
					&& isset($data[$itemNum+1]) && $data[$itemNum+1]->group_id === $row->group_id
					&& $data[$itemNum+1]->lowql > $ql
				) {
					$list .= $item;
					$list .= ', <yellow>[<end>' . $row->getLink(ql: $ql, text: (string)$ql) . '<yellow>]<end>';
				} else {
					$list .= $item;
				}
				$lastQL = $row->ql;
			}
		}
		if (isset($nameMatches)) {
			if (substr($list, -2, 2) === ', ') {
				$list = substr($list, 0, strlen($list) - 2) . '<red>]<end>, ';
			} else {
				$list .= '<red>]<end>';
			}
			unset($nameMatches);
		}
		$list = Safe::pregReplaceCallback(
			"/^([^<]+?)<red>\[<end>(.+)<red>\]<end>$/m",
			static function (array $matches): string {
				if (str_contains($matches[2], '<red>')) {
					return $matches[0];
				}
				return $matches[1].$matches[2];
			},
			$list
		);
		return $list;
	}

	public function itemNameMatchesSearch(string $itemName, ?string $search): bool {
		if (!isset($search)) {
			return false;
		}
		$tokens = preg_split("/\s+/", $search);
		foreach ($tokens as $token) {
			if (substr($token, 0, 1) === '-'
				&& stripos($itemName, substr($token, 1)) !== false) {
				return false;
			}
			if (substr($token, 0, 1) !== '-'
				&& stripos($itemName, $token) === false) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @template T of null|int
	 *
	 * @param T $ql
	 *
	 * @psalm-return (T is null ? null|AODBEntry : null|AODBItem)
	 *
	 * @phpstan-ignore-next-line
	 */
	public function findByName(string $name, ?int $ql=null): null|AODBEntry|AODBItem {
		$query = $this->db->table(AODBEntry::getTable())
			->where('name', $name)
			->orderByDesc('highql')
			->orderByDesc('highid');
		if ($ql !== null) {
			$query->where('lowql', '<=', $ql)->where('highql', '>=', $ql);
			$query->select('aodb.*');
			$query->selectRaw("{$ql} " . $query->as('ql'));
			return $query->asObj(AODBItem::class)->first();
		}
		return $query->asObj(AODBEntry::class)->first();
	}

	public function getItem(string $name, ?int $ql=null): ?string {
		$row = $this->findByName($name, $ql);
		if ($row === null) {
			$this->logger->warning("Could not find item '{item_name}' at QL '{ql}'", [
				'item_name' => $name,
				'ql' => $ql,
			]);
			return null;
		}
		$ql ??= $row->highql;
		return $row->getLink(ql: $ql);
	}

	public function getItemAndIcon(string $name, ?int $ql=null): string {
		$row = $this->findByName($name, $ql);
		if ($row === null) {
			if (isset($ql)) {
				$this->logger->warning("Could not find item '{item_name}' at QL '{ql}'", [
					'item_name' => $name,
					'ql' => $ql,
				]);
				return "{$name}@{$ql}";
			}
			$this->logger->warning("Could not find item '{item_name}'", [
				'item_name' => $name,
			]);
			return $name;
		}
		$ql ??= $row->highql;
		return $row->getIcon() . "\n" . $row->getLink(ql: $ql);
	}

	/**
	 * Get the longest common string of 2 strings
	 * The LCS of "Cheap Caterwaul X-17" and "Exceptional Caterwaul X-17"
	 * would be " Caterwaul X-17", so mind the included space!
	 *
	 * @param string $first  The first word to compare
	 * @param string $second The second word to compare
	 *
	 * @return string The longest common string of $first and $second
	 */
	public function getLongestCommonString(string $first, string $second): string {
		$first = explode(' ', $first);
		$second = explode(' ', $second);
		$longestCommonSubstringIndexInFirst = 0;
		$table = [];
		$largestFound = 0;

		$firstLength = count($first);
		$secondLength = count($second);
		for ($i = 0; $i < $firstLength; $i++) {
			for ($j = 0; $j < $secondLength; $j++) {
				if ($first[$i] === $second[$j]) {
					if (!isset($table[$i])) {
						$table[$i] = [];
					}

					$table[$i][$j] = 1;
					if ($i > 0 && $j > 0 && isset($table[$i-1][$j-1])) {
						$table[$i][$j] = $table[$i-1][$j-1] + 1;
					}

					if ($table[$i][$j] > $largestFound) {
						$largestFound = $table[$i][$j];
						$longestCommonSubstringIndexInFirst = $i - $largestFound + 1;
					}
				}
			}
		}
		if ($largestFound === 0) {
			return '';
		}
		return implode(' ', array_slice($first, $longestCommonSubstringIndexInFirst, $largestFound));
	}

	/**
	 * Get the longest common string of X words
	 * The LCS of
	 *  "Cheap Caterwaul X-17"
	 *  "Exceptional Caterwaul X-17"
	 *  and "Crappy Caterwaul"
	 * would be "Caterwaul", without the leading space!
	 *
	 * @param iterable<int,string> $words The words to compare
	 *
	 * @return string The longest common string of all given words
	 */
	public function getLongestCommonStringOfWords(iterable $words): string {
		$words = collect($words);
		if ($words->isEmpty()) {
			return '';
		}

		/** @var string */
		$firstWord = $words->shift();
		return trim($words->reduce($this->getLongestCommonString(...), $firstWord));
	}

	/** @return ?Skill */
	public function getSkillByID(int $id): ?Skill {
		return $this->skills[$id] ?? null;
	}

	/** @return Collection<int,Skill> */
	public function getSkillByIDs(int ...$ids): Collection {
		return $this->db->table(Skill::getTable())
			->whereIn('id', $ids)
			->asObj(Skill::class);
	}

	/** @return Collection<int,Skill> */
	public function searchForSkill(string $skillName): Collection {
		// check for exact match first, in order to disambiguate
		// between Bow and Bow special attack
		$query = $this->db->table(Skill::getTable());

		$results = $query->where($query->raw($query->colFunc('LOWER', 'name')), strtolower($skillName))
			->select('*')->distinct()
			->asObj(Skill::class);
		if ($results->containsOneItem()) {
			return $results;
		}

		$query = $this->db->table(Skill::getTable())->select('*')->distinct();

		$tmp = explode(' ', $skillName);
		$this->db->addWhereFromParams($query, $tmp, 'name');

		return $query->asObj(Skill::class);
	}

	/** @return Collection<int,ItemWithBuffs> */
	public function addBuffs(AODBEntry ...$items): Collection {
		$buffs = $this->db->table(ItemBuff::getTable())
			->whereIn('item_id', array_unique([...array_column($items, 'highid'), ...array_column($items, 'lowid')]))
			->asObj(ItemBuff::class);
		$skills = $this->getSkillByIDs(...$buffs->pluck('attribute_id')->unique()->toArray())
			->keyBy('id');

		/** @param Collection<ItemBuff> $buffs */
		$buffs = $buffs->groupBy('item_id')
			->map(static function (Collection $iBuffs, int $itemId) use ($skills): array {
				return $iBuffs->map(static function (ItemBuff $buff) use ($skills): ExtBuff {
					if (null === ($skill = $skills->get($buff->attribute_id))) {
						throw new \Exception("Unknown skill {$buff->attribute_id} encountered");
					}
					return new ExtBuff(
						skill: $skill,
						amount: $buff->amount,
					);
				})->toArray();
			});

		/** @var Collection<int,ItemWithBuffs> */
		$result = new Collection();
		foreach ($items as $item) {
			$new = ItemWithBuffs::fromEntry($item);
			$new->buffs = $buffs->get($new->lowid, []);
			if ($new->lowid !== $new->highid) {
				$new->buffs = array_merge($new->buffs, $buffs->get($new->highid, []));
			}
			$result->push($new);
		}
		return $result;
	}

	/** Check if an aoid is part of an item group */
	public function hasItemGroup(int $aoid): bool {
		return $this->db->table(ItemGroup::getTable())
			->where('item_id', $aoid)
			->exists();
	}
}
