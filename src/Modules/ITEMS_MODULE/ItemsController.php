<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Exception;
use JsonException;
use Nadybot\Core\{
	CommandReply,
	DB,
	Http,
	HttpResponse,
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
 *		command     = 'updateitems',
 *		accessLevel = 'guild',
 *		description = 'Downloads the latest version of the items db',
 *		help        = 'updateitems.txt'
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
		$this->db->loadSQLFile($this->moduleName, "aodb");
		$this->db->loadSQLFile($this->moduleName, "item_groups");
		$this->db->loadSQLFile($this->moduleName, "item_group_names");
		
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
		$sql = "SELECT * FROM aodb WHERE lowid = ? UNION SELECT * FROM aodb WHERE highid = ? LIMIT 1";
		return $this->db->fetch(AODBEntry::class, $sql, $id, $id);
	}

	/**
	 * @HandlesCommand("updateitems")
	 * @Matches("/^updateitems$/i")
	 */
	public function updateItemsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply("Starting update");
		$this->downloadNewestItemsdb(function(string $msg) use ($sendto): void {
			$sendto->reply($msg);
		});
	}

	/**
	 * @Event("timer(7days)")
	 * @Description("Check to make sure items db is the latest version available")
	 */
	public function checkForUpdate(): void {
		// Do not run directly after the bot starts, so we don't flood GitHub
		// when the bot errors
		if ($this->chatBot->getUptime() < 60) {
			return;
		}
		$this->downloadNewestItemsdb(function(string $msg): void {
			if (preg_match("/^The items database has been updated/", $msg)) {
				$this->chatBot->sendGuild($msg);
			}
		});
	}

	public function downloadNewestItemsdb(?callable $callback=null): void {
		$this->logger->log('DEBUG', "Starting items db update");
		// get list of files in ITEMS_MODULE
		$this->http
			->get("https://api.github.com/repos/Nadybot/Nadybot/contents/src/Modules/ITEMS_MODULE")
			->withHeader("Accept", "application/vnd.github.v3+json")
			->withHeader('User-Agent', 'Nadybot')
			->withCallback(function (HttpResponse $response) use ($callback): void {
				$this->handleGithubFilelist($response, $callback);
			});
	}

	protected function handleGithubFilelist(?HttpResponse $response, ?callable $callback=null): void {
		$databases = ['aodb', 'buffs', 'item_buffs', 'item_types', 'item_groups', 'item_group_names'];
		if ($response->error || $response->body === null) {
			$this->logger->log("ERROR", "Invalid reply received from GitHub when requesting items filelist");
			if (isset($callback)) {
				$callback("Invalid reply received from GitHub while getting filelist");
			}
			return;
		}
		try {
			$files = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->logger->log("ERROR", "Invalid JSON received from GitHub when requesting items filelist: {$response->body}");
			if (isset($callback)) {
				$callback("Invalid JSON received from GitHub while getting filelist");
			}
			return;
		}
		$updateStatus = [];
		foreach ($databases as $currentDB) {
			try {
				// find the latest items db version on the server
				$latestVersion = null;
				foreach ($files as $file) {
					if (preg_match("/^${currentDB}(.*)\\.sql$/i", $file->name, $arr)) {
						if ($latestVersion === null) {
							$latestVersion = $arr[1];
						} elseif ($this->util->compareVersionNumbers($arr[1], $latestVersion)) {
							$latestVersion = $arr[1];
						}
					}
				}
			} catch (Exception $e) {
				$msg = "Error updating items db: " . $e->getMessage();
				$this->logger->log('ERROR', $msg);
				if (isset($callback)) {
					$callback($msg);
				}
				return;
			}

			$msg = [];
			if ($latestVersion !== null) {
				$currentVersion = $this->settingManager->get("${currentDB}_db_version");

				// if server version is greater than current version, download and load server version
				if ($currentVersion === false || $this->util->compareVersionNumbers($latestVersion, $currentVersion) > 0) {
					// download server version and save to ITEMS_MODULE directory
					$this->http
						->get("https://raw.githubusercontent.com/Nadybot/Nadybot/stable/src/Modules/ITEMS_MODULE/${currentDB}{$latestVersion}.sql")
						->withHeader('User-Agent', 'Nadybot')
						->withCallback(function(?HttpResponse $fileResponse) use ($currentDB, $latestVersion, $currentVersion, $callback, &$updateStatus, $databases): void {
							if ($fileResponse === null) {
								if (isset($callback)) {
									$callback("Error downloading ${currentDB}{$latestVersion}.");
									return;
								}
							}
							$contents = $fileResponse->body;

							$fh = fopen(__DIR__ . "/${currentDB}{$latestVersion}.sql", 'w');
							fwrite($fh, $contents);
							fclose($fh);

							$this->db->beginTransaction();

							// load the sql file into the db
							$this->db->loadSQLFile("ITEMS_MODULE", $currentDB);

							$this->db->commit();

							$this->logger->log('INFO', "Items db $currentDB updated from '$currentVersion' to '$latestVersion'");

							$updateStatus[$currentDB] = "The items database <highlight>$currentDB<end> has been updated from <red>$currentVersion<end> to <green>$latestVersion<end>";
							if (count($updateStatus) === count($databases)) {
								$callback(join("\n", array_values($updateStatus)));
							}
						});
				} else {
					$this->logger->log('DEBUG', "Items db $currentDB already up to date '$currentVersion'");
					$updateStatus[$currentDB] = "The items database <highlight>$currentDB<end> is already up to date at version <green>$currentVersion<end>";
					if (count($updateStatus) === count($databases)) {
						$callback(join("\n", array_values($updateStatus)));
					}
				}
			} else {
				$this->logger->log('ERROR', "Could not find latest items db $currentDB on server");
				$updateStatus[$currentDB] = "There was a problem finding the latest version of $currentDB on the server";
				if (count($updateStatus) === count($databases)) {
					$callback(join("\n", array_values($updateStatus)));
				}
			}
		}

		$this->logger->log('DEBUG', "Finished items db update");

		return;
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
		$footer = "Item DB rips created using the $aoiaPlusLink tool.";

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
		$tmp = explode(" ", $search);
		[$query, $params] = $this->util->generateQueryFromParams($tmp, 'name');

		if ($ql !== null) {
			$query .= " AND aodb.lowql <= ? AND aodb.highql >= ?";
			$params []= $ql;
			$params []= $ql;
		}
		$sql = "SELECT ".
				"COALESCE(a2.name,a1.name,foo.name) AS name, ".
				"n.name AS group_name, ".
				"foo.icon, ".
				"g.group_id, ".
				"COALESCE(a1.lowid,a2.lowid,foo.lowid) AS lowid, ".
				"COALESCE(a1.highid,a2.highid,foo.highid) AS highid, ".
				"COALESCE(a1.lowql,a2.highql,foo.highql,foo.lowql) AS ql, ".
				"COALESCE(a1.lowql,a2.lowql,foo.lowql) AS lowql, ".
				"COALESCE(a1.highql,a2.highql,foo.highql) AS highql ".
			"FROM (".
				"SELECT aodb.*, g.group_id ".
				"FROM aodb ".
				"LEFT JOIN item_groups g ON (g.item_id=aodb.lowid) ".
				"WHERE $query ".
				"GROUP BY COALESCE(g.group_id,aodb.lowid) ".
				"ORDER BY ".
					"aodb.name ASC, ".
					"aodb.highql DESC ".
				"LIMIT ".$this->settingManager->getInt('maxitems').
			") AS foo ".
			"LEFT JOIN item_groups g ON(foo.group_id=g.group_id) ".
			"LEFT JOIN item_group_names n ON(foo.group_id=n.group_id) ".
			"LEFT JOIN aodb a1 ON(g.item_id=a1.lowid) ".
			"LEFT JOIN aodb a2 ON(g.item_id=a2.highid) ".
			"ORDER BY g.id ASC";
		$data = $this->db->fetchAll(ItemSearchResult::class, $sql, ...$params);
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
			return trim($this->formatSearchResults($data, $ql, false));
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
		$blob .= $this->formatSearchResults($data, $ql, true);
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
	public function formatSearchResults(array $data, ?int $ql, bool $showImages) {
		$list = '';
		$oldGroup = null;
		for ($itemNum = 0; $itemNum < count($data); $itemNum++) {
			$row = $data[$itemNum];
			$newGroup = false;
			if (!isset($row->group_id) && $ql && $ql !== $row->ql) {
				continue;
			}
			if (!isset($row->group_id) || $row->group_id !== $oldGroup) {
				$lastQL = null;
				$newGroup = true;
				// If this is a group of items, name them by their longest common name
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
				} elseif ($lastQL === $row->ql) {
					continue;
				} else {
					$list .= ", ";
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
		return $list;
	}
	
	public function findByName(string $name, ?int $ql=null): ?AODBEntry {
		if ($ql === null) {
			return $this->db->fetch(
				AODBEntry::class,
				"SELECT * FROM aodb WHERE name = ? ORDER BY highql DESC, highid DESC",
				$name
			);
		}
		return $this->db->fetch(
			AODBEntry::class,
			"SELECT * FROM aodb WHERE name = ? AND lowql <= ? AND highql >= ? ORDER BY highid DESC",
			$name,
			$ql,
			$ql
		);
	}

	public function getItem(string $name, ?int $ql=null): ?string {
		$row = $this->findByName($name, $ql);
		$ql ??= $row->highql;
		if ($row === null) {
			$this->logger->log("WARN", "Could not find item '$name' at QL '$ql'");
			return "{$name}@{$ql}";
		}
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
