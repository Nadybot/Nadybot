<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Http,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
	Util,
};

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'items',
 *		accessLevel = 'all',
 *		description = 'Searches for an item using the default items db',
 *		help        = 'items.txt',
 *		alias		= 'i'
 *	)
 *	@DefineCommand(
 *		command     = 'itemid',
 *		accessLevel = 'all',
 *		description = 'Searches for an item by id',
 *		help        = 'items.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'id',
 *		accessLevel = 'all',
 *		description = 'Searches for an itemid by name',
 *		help        = 'items.txt'
 *	)
 */
class ItemsController {

	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Items");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/aodb.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/item_groups.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/item_group_names.csv");

		$this->settingManager->add(
			$this->moduleName,
			'maxitems',
			'Number of items shown on the list',
			'edit',
			'number',
			'40',
			'30;40;50;60'
		);
	}

	/**
	 * @HandlesCommand("items")
	 * @Matches("/^items (\d+) (.+)$/i")
	 * @Matches("/^items (.+)$/i")
	 */
	public function itemsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->findItems($args);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("itemid")
	 * @Matches("/^itemid (\d+)$/i")
	 */
	public function itemIdCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$row = $this->findById($id);
		if ($row === null) {
			$msg = "No item found with id <highlight>$id<end>.";
			$sendto->reply($msg);
			return;
		}
		$blob = "";
		foreach ($row as $key => $value) {
			$blob .= "$key: <highlight>$value<end>\n";
		}
		$row->ql = $row->highql;
		if ($row->lowid === $id) {
			$row->ql = $row->lowql;
		}
		$blob .= "\n" . $this->formatSearchResults([$row], null, true);
		$msg = "Details about item ID ".
			$this->text->makeBlob((string)$id, $blob, "Details about item ID $id").
			" ({$row->name})";

		$sendto->reply($msg);
	}

	public function findById(int $id): ?AODBEntry {
		return $this->db->table("aodb")
			->where("lowid", $id)
			->union(
				$this->db->table("aodb")
					->where("highid", $id)
			)
			->limit(1)
			->asObj(AODBEntry::class)
			->first();
	}

	/**
	 * @HandlesCommand("id")
	 * @Matches("/^id (.+)$/i")
	 */
	public function idCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = $args[1];

		$query = $this->db->table("aodb AS a")
			->leftJoin("item_groups AS g", "g.item_id", "a.lowid")
			->leftJoin("item_group_names AS gn", "g.group_id", "gn.group_id")
			->orderByColFunc("COALESCE", ["gn.name", "a.name"])
			->orderBy("a.lowql")
			->limit($this->settingManager->getInt('maxitems'));
		$tmp = explode(" ", $search);
		$this->db->addWhereFromParams($query, $tmp, "a.name");
		/** @var AODBEntry[] */
		$items = $query->asObj(AODBEntry::class)->toArray();
		if (!count($items)) {
			$sendto->reply("No items found matching <highlight>{$search}<end>.");
			return;
		}
		$blob = "<header2><u>Low ID    Low QL    High ID    High QL    Name                                         </u><end>\n";
		foreach ($items as $item) {
			$itemLinkLow = $this->text->makeItem($item->lowid, $item->highid, $item->lowql, (string)$item->lowid);
			$itemLinkHigh = $this->text->makeItem($item->lowid, $item->highid, $item->highql, (string)$item->highid);
			$blob .= str_replace((string)$item->lowid, $itemLinkLow, $this->text->alignNumber($item->lowid, 6)).
				"       " . $this->text->alignNumber($item->lowql, 3).
				"     " . (($item->highid === $item->lowid) ? "        " : str_replace((string)$item->highid, $itemLinkHigh, $this->text->alignNumber($item->highid, 6))).
				"         " . (($item->highid === $item->lowid) ? "         <black>|<end>" : $this->text->alignNumber($item->highql, 3) . "    ").
				$item->name . "\n";
		}
		if (count($items) === $this->settingManager->getInt('maxitems')) {
			$blob .= "\n\n<highlight>*Results have been limited to the first " . $this->settingManager->get("maxitems") . " results.<end>";
		}
		$msg = $this->text->makeBlob("Items matching \"{$search}\" (" . count($items) . ")", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @param array $args
	 * @return string|string[]
	 */
	public function findItems(array $args) {
		$search = $args[1];
		$ql = null;
		if (count($args) === 3) {
			$ql = (int)$args[1];
			if ($ql < 1 || $ql > 500) {
				return "QL must be between 1 and 500.";
			}
			$search = $args[2];
		}

		$search = htmlspecialchars_decode($search);

		// local database
		$data = $this->findItemsFromLocal($search, $ql);

		$aoiaPlusLink = $this->text->makeChatcmd("AOIA+", "/start https://sourceforge.net/projects/aoiaplus");
		$footer = "QLs between <red>[<end>brackets<red>]<end> denote items matching your name search\n".
			"Item DB rips created using the $aoiaPlusLink tool.";

		$msg = $this->createItemsBlob($data, $search, $ql, $this->settingManager->get('aodb_db_version'), 'local', $footer);

		return $msg;
	}

	/**
	 * Search for items in the local database
	 * @param string $search The searchterm
	 * @param null|int $ql The QL to return the results in
	 * @return ItemSearchResult
	 */
	public function findItemsFromLocal(string $search, ?int $ql): array {
		$innerQuery = $this->db->table("aodb AS a")
			->leftJoin("item_groups AS g", "g.item_id", "a.lowid");
		$tmp = explode(" ", $search);
		$this->db->addWhereFromParams($innerQuery, $tmp, "name");

		if ($ql !== null) {
			$innerQuery->where("a.lowql", "<=", $ql)
				->where("a.highql", ">=", $ql);
		}
		$innerQuery->groupByRaw($innerQuery->colFunc("COALESCE", ["g.group_id", "a.lowid"]))
			->groupBy("a.lowid", "a.highid", "a.lowql", "a.highql", "a.name")
			->groupBy("a.icon", "a.froob_friendly", "a.slot", "a.flags", "g.group_id")
			->orderBy("a.name")
			->orderByDesc("a.highql")
			->limit($this->settingManager->getInt('maxitems'))
			->select("a.*", "g.group_id");
		$query = $this->db->fromSub($innerQuery, "foo")
			->leftJoin("item_groups AS g", "foo.group_id", "g.group_id")
			->leftJoin("item_group_names AS n", "foo.group_id", "n.group_id")
			->leftJoin("aodb AS a1", "g.item_id", "a1.lowid")
			->leftJoin("aodb AS a2", "g.item_id", "a2.highid")
			->orderBy("g.id");
		$query->selectRaw($query->colFunc("COALESCE", ["a2.name", "a1.name", "foo.name"], "name"))
			->addSelect("n.name AS group_name")
			->addSelect("foo.icon")
			->addSelect("g.group_id")
			->selectRaw($query->colFunc("COALESCE", ["a1.lowid", "a2.lowid", "foo.lowid"], "lowid"))
			->selectRaw($query->colFunc("COALESCE", ["a1.highid", "a2.highid", "foo.highid"], "highid"))
			->selectRaw($query->colFunc("COALESCE", ["a1.lowql", "a2.highql", "foo.highql"], "ql"))
			->selectRaw($query->colFunc("COALESCE", ["a1.lowql", "a2.lowql", "foo.lowql"], "lowql"))
			->selectRaw($query->colFunc("COALESCE", ["a1.highql", "a2.highql", "foo.highql"], "highql"));
		$data = $query->asObj(ItemSearchResult::class)->toArray();
		// $data = $this->orderSearchResults($data, $search);

		return $data;
	}

	/**
	 * @param ItemSearchResult[] $data
	 * @param string $search
	 * @param null|int $ql
	 * @param string $version
	 * @param string $server
	 * @param string $footer
	 * @param mixed|null $elapsed
	 * @return string|string[]
	 */
	public function createItemsBlob(array $data, string $search, ?int $ql, string $version, string $server, string $footer, $elapsed=null) {
		$numItems = count($data);
		$groups = count(
			array_unique(
				array_diff(
					array_map(function(ItemSearchResult $row) {
						return $row->group_id;
					}, $data),
					[null],
				)
			)
		) + count(
			array_filter($data, function(ItemSearchResult $row) {
				return $row->group_id === null;
			})
		);

		if ($numItems === 0) {
			if ($ql !== null) {
				$msg = "No QL <highlight>$ql<end> items found matching <highlight>$search<end>.";
			} else {
				$msg = "No items found matching <highlight>$search<end>.";
			}
			return $msg;
		} elseif ($groups < 4) {
			return trim($this->formatSearchResults($data, $ql, false, $search));
		}
		$blob = "Version: <highlight>$version<end>\n";
		if ($ql !== null) {
			$blob .= "Search: <highlight>QL $ql $search<end>\n";
		} else {
			$blob .= "Search: <highlight>$search<end>\n";
		}
		$blob .= "Server: <highlight>" . $server . "<end>\n";
		if ($elapsed) {
			$blob .= "Time: <highlight>" . round($elapsed, 2) . "s<end>\n";
		}
		$blob .= "\n";
		$blob .= $this->formatSearchResults($data, $ql, true, $search);
		if ($numItems === $this->settingManager->getInt('maxitems')) {
			$blob .= "\n\n<highlight>*Results have been limited to the first " . $this->settingManager->get("maxitems") . " results.<end>";
		}
		$blob .= "\n\n" . $footer;
		$link = $this->text->makeBlob("Item Search Results ($numItems)", $blob);

		return $link;
	}

	/**
	 * Sort by exact word matches higher than partial word matches
	 * @param ItemSearchResult[] $data
	 * @param string $search
	 * @return ItemSearchResult[]
	 */
	public function orderSearchResults(array $data, string $search): array {
		$searchTerms = explode(" ", $search);
		foreach ($data as $row) {
			if (strcasecmp($search, $row->name) == 0) {
				$numExactMatches = 100;
				continue;
			}
			$itemKeywords = preg_split("/\s/", $row->name);
			$numExactMatches = 0;
			foreach ($itemKeywords as $keyword) {
				foreach ($searchTerms as $searchWord) {
					if (strcasecmp($keyword, $searchWord) == 0) {
						$numExactMatches++;
						break;
					}
				}
			}
			$row->numExactMatches = $numExactMatches;
		}

		/*
		$this->util->mergesort($data, function($a, $b) {
			if ($a->numExactMatches == $b->numExactMatches) {
				return 0;
			} else {
				return ($a->numExactMatches > $b->numExactMatches) ? -1 : 1;
			}
		});
		*/

		return $data;
	}

	/**
	 * @param ItemSearchResult[] $data
	 * @param null|int $ql
	 * @param bool $showImages
	 * @return string
	 */
	public function formatSearchResults(array $data, ?int $ql, bool $showImages, ?string $search=null) {
		$list = '';
		$oldGroup = null;
		for ($itemNum = 0; $itemNum < count($data); $itemNum++) {
			$row = $data[$itemNum];
			$row->origName = $row->name;
			$newGroup = false;
			if (!isset($row->group_id) && $ql && $ql !== $row->ql) {
				continue;
			}
			if (!isset($row->group_id) || $row->group_id !== $oldGroup) {
				$lastQL = null;
				$newGroup = true;
				// If this is a group of items, name them by their longest common name
				if (isset($nameMatches)) {
					if (substr($list, -2, 2) === ", ") {
						$list = substr($list, 0, strlen($list) - 2) . "<red>]<end>, ";
					} else {
						$list .= "<red>]<end>";
					}
					unset($nameMatches);
				}
				if (isset($row->group_id)) {
					$itemNames = [];
					for ($j=$itemNum; $j < count($data); $j++) {
						if ($data[$j]->group_id === $row->group_id) {
							$itemNames []= $data[$j]->name;
						} else {
							break;
						}
					}
					if (!isset($row->group_name)) {
						$row->name = $this->getLongestCommonStringOfWords($itemNames);
					} else {
						$row->name = $row->group_name;
					}
				}
				if ($list !== '') {
					$list .= "\n";
				}
				if ($showImages) {
					$list .= "\n<pagebreak>" . $this->text->makeImage($row->icon) . "\n";
				}
				if (isset($row->group_id)) {
					$list .= $row->name;
					if ($showImages) {
						$list .= "\n";
					} else {
						$list .= " - ";
					}
				}
			}
			$oldGroup = isset($row->group_id) ? $row->group_id : null;
			if (!isset($row->group_id)) {
				$list .= $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->name);
				$list .= " (QL $row->ql)";
			} else {
				if ($newGroup === true) {
					$list .= "QL ";
				} elseif (isset($lastQL) && $lastQL === $row->ql) {
					continue;
				} else {
					$list .= ", ";
				}
				if (isset($search) && $this->itemNameMatchesSearch($row->origName, $search)) {
					if (!isset($nameMatches)) {
						$list .= "<red>[<end>";
						$nameMatches = true;
					}
				} elseif (isset($nameMatches)) {
					if (substr($list, -2, 2) === ", ") {
						$list = substr($list, 0, strlen($list) - 2) . "<red>]<end>, ";
					} else {
						$list .= "<red>]<end>";
					}
					unset($nameMatches);
				}
				$item = $this->text->makeItem($row->lowid, $row->highid, $row->ql, (string)$row->ql);
				if ($ql === $row->ql) {
					$list .= "<yellow>[<end>$item<yellow>]<end>";
				} elseif ($ql > $row->lowql && $ql < $row->highql && $ql < $row->ql) {
					$list .= "<yellow>[<end>" . $this->text->makeItem($row->lowid, $row->highid, $ql, (string)$ql) . "<yellow>]<end>";
					$list .= ", $item";
				} elseif (
					$ql > $row->lowql && $ql < $row->highql && $ql > $row->ql &&
					isset($data[$itemNum+1]) && $data[$itemNum+1]->group_id === $row->group_id &&
					$data[$itemNum+1]->lowql > $ql
				) {
					$list .= $item;
					$list .= ", <yellow>[<end>" . $this->text->makeItem($row->lowid, $row->highid, $ql, (string)$ql) . "<yellow>]<end>";
				} else {
					$list .= $item;
				}
				$lastQL = $row->ql;
			}
		}
		if (isset($nameMatches)) {
			if (substr($list, -2, 2) === ", ") {
				$list = substr($list, 0, strlen($list) - 2) . "<red>]<end>, ";
			} else {
				$list .= "<red>]<end>";
			}
			unset($nameMatches);
		}
		$list = preg_replace_callback(
			"/^([^<]+?)<red>\[<end>(.+)<red>\]<end>$/m",
			function(array $matches): string {
				if (strpos($matches[2], "<red>") !== false) {
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
			if (substr($token, 0, 1) === "-"
				&& stripos($itemName, substr($token, 1)) !== false) {
				return false;
			}
			if (substr($token, 0, 1) !== "-"
				&& stripos($itemName, $token) === false) {
				return false;
			}
		}
		return true;
	}

	public function findByName(string $name, ?int $ql=null): ?AODBEntry {
		$query = $this->db->table("aodb")
			->where("name", $name)
			->orderByDesc("highql")
			->orderByDesc("highid");
		if ($ql !== null) {
			$query->where("lowql", "<=", $ql)->where("highql", ">=", $ql);
		}
		return $query->asObj(AODBEntry::class)->first();
	}

	public function getItem(string $name, ?int $ql=null): ?string {
		$row = $this->findByName($name, $ql);
		if ($row === null) {
			$this->logger->log("WARN", "Could not find item '$name' at QL '$ql'");
			return null;
		}
		$ql ??= $row->highql;
		return $this->text->makeItem($row->lowid, $row->highid, $ql, $row->name);
	}

	public function getItemAndIcon(string $name, ?int $ql=null): string {
		$row = $this->findByName($name, $ql);
		if ($row === null) {
			if (isset($ql)) {
				$this->logger->log("WARN", "Could not find item '$name' at QL '$ql'");
				return "{$name}@{$ql}";
			}
			$this->logger->log("WARN", "Could not find item '$name'");
			return $name;
		}
		$ql ??= $row->highql;
		return $this->text->makeImage($row->icon) . "\n" .
			$this->text->makeItem($row->lowid, $row->highid, $ql, $row->name);
	}

	/**
	 * Get the longest common string of 2 strings
	 *
	 * The LCS of "Cheap Caterwaul X-17" and "Exceptional Caterwaul X-17"
	 * would be " Caterwaul X-17", so mind the included space!
	 *
	 * @param string $first  The first word to compare
	 * @param string $second The second word to compare
	 * @return string The longest common string of $first and $second
	 */
	public function getLongestCommonString(string $first, string $second): string {
		$first = explode(" ", $first);
		$second = explode(" ", $second);
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
			return "";
		} else {
			return implode(" ", array_slice($first, $longestCommonSubstringIndexInFirst, $largestFound));
		}
	}

	/**
	 * Get the longest common string of X words
	 *
	 * The LCS of
	 *  "Cheap Caterwaul X-17"
	 *  "Exceptional Caterwaul X-17"
	 *  and "Crappy Caterwaul"
	 * would be "Caterwaul", without the leading space!
	 *
	 * @param string[] $words The words to compare
	 * @return string  The longest common string of all given words
	 */
	public function getLongestCommonStringOfWords(array $words): string {
		return trim(
			array_reduce(
				$words,
				[$this, 'getLongestCommonString'],
				array_shift($words)
			)
		);
	}
}
