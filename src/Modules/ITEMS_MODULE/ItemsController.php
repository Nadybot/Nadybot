<?php

namespace Budabot\Modules\ITEMS_MODULE;

use Exception;

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
	
	public $moduleName;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;

	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/** @Setup */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "aodb");
		$this->db->loadSQLFile($this->moduleName, "item_groups");
		$this->db->loadSQLFile($this->moduleName, "item_group_names");
		
		$this->settingManager->add($this->moduleName, 'maxitems', 'Number of items shown on the list', 'edit', 'number', '40', '30;40;50;60');
	}

	/**
	 * @HandlesCommand("items")
	 * @Matches("/^items ([0-9]+) (.+)$/i")
	 * @Matches("/^items (.+)$/i")
	 */
	public function itemsCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->findItems($args);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("itemid")
	 * @Matches("/^itemid ([0-9]+)$/i")
	 */
	public function itemIdCommand($message, $channel, $sender, $sendto, $args) {
		$id = $args[1];

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
		if ($row->lowid == $id) {
			$row->ql = $row->lowql;
		}
		$blob .= "\n" . $this->formatSearchResults(array($row), null, true);
		$msg = "Details about item ID ".
			$this->text->makeBlob($id, $blob, "Details about item ID $id").
			" ({$row->name})";

		$sendto->reply($msg);
	}
	
	public function findById($id) {
		$sql = "SELECT * FROM aodb WHERE lowid = ? UNION SELECT * FROM aodb WHERE highid = ? LIMIT 1";
		return $this->db->queryRow($sql, $id, $id);
	}

	/**
	 * @HandlesCommand("updateitems")
	 * @Matches("/^updateitems$/i")
	 */
	public function updateitemsCommand($message, $channel, $sender, $sendto) {
		$msg = $this->downloadNewestItemsdb();
		$sendto->reply($msg);
	}

	/**
	 * @Event("timer(7days)")
	 * @Description("Check to make sure items db is the latest version available")
	 */
	public function checkForUpdate() {
		$msg = $this->downloadNewestItemsdb();
		if (preg_match("/^The items database has been updated/", $msg)) {
			$this->chatBot->sendGuild($msg);
		}
	}

	public function downloadNewestItemsdb() {
		$this->logger->log('DEBUG', "Starting items db update");

		$databases = array('aodb', 'item_buffs', 'item_types');

		// get list of files in ITEMS_MODULE
		$response = $this->http
			->get("https://api.github.com/repos/Nadyita/Budabot/contents/src/Modules/ITEMS_MODULE")
			->withHeader("Accept", "application/vnd.github.v3+json")
			->withHeader('User-Agent', 'Budabot')
			->waitAndReturnResponse();

		$msg = array();
		foreach ($databases as $currentDB) {
			try {
				$json = json_decode($response->body);
			
				// find the latest items db version on the server
				$latestVersion = null;
				foreach ($json as $item) {
					if (preg_match("/^${currentDB}(.*)\\.sql$/i", $item->name, $arr)) {
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
				return $msg;
			}

			$msg = array();
			if ($latestVersion !== null) {
				$currentVersion = $this->settingManager->get("${currentDB}_db_version");

				// if server version is greater than current version, download and load server version
				if ($currentVersion === false || $this->util->compareVersionNumbers($latestVersion, $currentVersion) > 0) {
					// download server version and save to ITEMS_MODULE directory
					$contents = $this->http
						->get("https://raw.githubusercontent.com/Nadyita/Budabot/master/src/Modules/ITEMS_MODULE/${currentDB}{$latestVersion}.sql")
						->withHeader('User-Agent', 'Budabot')
						->waitAndReturnResponse()
						->body;

					$fh = fopen(__DIR__ . "/${currentDB}{$latestVersion}.sql", 'w');
					fwrite($fh, $contents);
					fclose($fh);

					$this->db->beginTransaction();

					// load the sql file into the db
					$this->db->loadSQLFile("ITEMS_MODULE", $currentDB);

					$this->db->commit();

					$this->logger->log('INFO', "Items db $currentDB updated from '$currentVersion' to '$latestVersion'");
					$msg []= "The items database <highlight>$currentDB<end> has been updated from <red>$currentVersion<end> to <green>$latestVersion<end>";
				} else {
					$this->logger->log('DEBUG', "Items db $currentDB already up to date '$currentVersion'");
					$msg []= "The items database <highlight>$currentDB<end> is already up to date at version <green>$currentVersion<end>";
				}
			} else {
				$this->logger->log('ERROR', "Could not find latest items db $currentDB on server");
				$msg []= "There was a problem finding the latest version of $currentDB on the server";
			}
		}

		$this->logger->log('DEBUG', "Finished items db update");

		return implode("\n", $msg);
	}

	public function findItems($args) {
		if (count($args) == 3) {
			$ql = $args[1];
			if (!($ql >= 1 && $ql <= 500)) {
				return "QL must be between 1 and 500.";
			}
			$search = $args[2];
		} else {
			$search = $args[1];
			$ql = false;
		}

		$search = htmlspecialchars_decode($search);
	
		// local database
		$data = $this->findItemsFromLocal($search, $ql);

		$aoiaPlusLink = $this->text->makeChatcmd("AOIA+", "/start https://sourceforge.net/projects/aoiaplus");
		$footer = "Item DB rips created using the $aoiaPlusLink tool.";

		$msg = $this->createItemsBlob($data, $search, $ql, $this->settingManager->get('aodb_db_version'), 'local', $footer);

		return $msg;
	}
	
	public function findItemsFromLocal($search, $ql) {
		$tmp = explode(" ", $search);
		list($query, $params) = $this->util->generateQueryFromParams($tmp, 'name');

		if ($ql) {
			$query .= " AND aodb.lowql <= ? AND aodb.highql >= ?";
			$params []= $ql;
			$params []= $ql;
		}
		$sql = "
			SELECT
				COALESCE(a2.name,a1.name,foo.name) AS name,
				n.name AS group_name,
				foo.icon,
				g.group_id,
				COALESCE(a1.lowid,a2.lowid,foo.lowid) AS lowid,
				COALESCE(a1.highid,a2.highid,foo.highid) AS highid,
				COALESCE(a1.lowql,a2.highql,foo.highql,foo.lowql) AS ql,
				COALESCE(a1.lowql,a2.lowql,foo.lowql) AS lowql,
				COALESCE(a1.highql,a2.highql,foo.highql) AS highql
			FROM (
				SELECT
					aodb.*,
					g.group_id
				FROM aodb
				LEFT JOIN item_groups g ON (g.item_id=aodb.lowid)
				WHERE $query
				GROUP BY COALESCE(g.group_id,aodb.lowid)
				ORDER BY
					aodb.name ASC,
					aodb.highql DESC
				LIMIT ".$this->settingManager->get('maxitems')."
			) AS foo
			LEFT JOIN item_groups g ON(foo.group_id=g.group_id)
			LEFT JOIN item_group_names n ON(foo.group_id=n.group_id)
			LEFT JOIN aodb a1 ON(g.item_id=a1.lowid)
			LEFT JOIN aodb a2 ON(g.item_id=a2.highid)
			ORDER BY g.id ASC
		";
		$data = $this->db->query($sql, $params);
		$data = $this->orderSearchResults($data, $search);
		
		return $data;
	}
	
	public function createItemsBlob($data, $search, $ql, $version, $server, $footer, $elapsed=null) {
		$num = count($data);
		$groups = count(
			array_unique(
				array_diff(
					array_map(function($row) {
						return $row->group_id;
					}, $data),
					array(null),
				)
			)
		) + count(
			array_filter($data, function($row) {
				return $row->group_id === null;
			})
		);

		if ($num == 0) {
			if ($ql) {
				$msg = "No QL <highlight>$ql<end> items found matching <highlight>$search<end>.";
			} else {
				$msg = "No items found matching <highlight>$search<end>.";
			}
			return $msg;
		} elseif ($groups < 4) {
			return trim($this->formatSearchResults($data, $ql, false));
		} else {
			$blob = "Version: <highlight>$version<end>\n";
			if ($ql) {
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
			if ($num == $this->settingManager->get('maxitems')) {
				$blob .= "\n\n<highlight>*Results have been limited to the first " . $this->settingManager->get("maxitems") . " results.<end>";
			}
			$blob .= "\n\n" . $footer;
			$link = $this->text->makeBlob("Item Search Results ($num)", $blob);

			return $link;
		}
	}
	
	// sort by exact word matches higher than partial word matches
	public function orderSearchResults($data, $search) {
		$searchTerms = explode(" ", $search);
		foreach ($data as $row) {
			if (strcasecmp($search, $row->name) == 0) {
				$numExactMatches = 100;
			} else {
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

	public function formatSearchResults($data, $ql, $showImages) {
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
					$itemNames = array();
					for ($j=$itemNum; $j < count($data); $j++) {
						if ($data[$j]->group_id === $row->group_id) {
							$itemNames []= $data[$j]->name;
						} else {
							break;
						}
					}
					$row->name = $row->group_name;
					if (!isset($row->group_name)) {
						$row->name = $this->getLongestCommonStringOfWords($itemNames);
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
				$item = $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->ql);
				if ($ql === $row->ql) {
					$list .= "<yellow>[<end>$item<yellow>]<end>";
				} elseif ($ql > $row->lowql && $ql < $row->highql && $ql < $row->ql) {
					$list .= "<yellow>[<end>" . $this->text->makeItem($row->lowid, $row->highid, $ql, $ql) . "<yellow>]<end>";
					$list .= ", $item";
				} elseif (
					$ql > $row->lowql && $ql < $row->highql && $ql > $row->ql &&
					isset($data[$itemNum+1]) && $data[$itemNum+1]->group_id === $row->group_id &&
					$data[$itemNum+1]->lowql > $ql
				) {
					$list .= $item;
					$list .= ", <yellow>[<end>" . $this->text->makeItem($row->lowid, $row->highid, $ql, $ql) . "<yellow>]<end>";
				} else {
					$list .= $item;
				}
				$lastQL = $row->ql;
			}
		}
		return $list;
	}
	
	private function escapeDescription($arr) {
		return "<description>" . htmlspecialchars($arr[1]) . "</description>";
	}
	
	public function findByName($name, $ql=null) {
		if ($ql === null) {
			return $this->db->queryRow("SELECT * FROM aodb WHERE name = ? ORDER BY highql DESC, highid DESC", $name);
		} else {
			return $this->db->queryRow("SELECT * FROM aodb WHERE name = ? AND lowql <= ? AND highql >= ? ORDER BY highid DESC", $name, $ql, $ql);
		}
	}

	public function getItem($name, $ql=null) {
		$row = $this->findByName($name, $ql);
		$ql = ($ql === null ? $row->highql : $ql);
		if ($row === null) {
			$this->logger->log("WARN", "Could not find item '$name' at QL '$ql'");
		} else {
			return $this->text->makeItem($row->lowid, $row->highid, $ql, $row->name);
		}
	}
	
	public function getItemAndIcon($name, $ql=null) {
		$row = $this->findByName($name, $ql);
		$ql = ($ql === null ? $row->highql : $ql);
		if ($row === null) {
			$this->logger->log("WARN", "Could not find item '$name' at QL '$ql'");
		} else {
			return $this->text->makeImage($row->icon) . "\n" .
				$this->text->makeItem($row->lowid, $row->highid, $ql, $row->name);
		}
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
	public function getLongestCommonString($first, $second) {
		$first = explode(" ", $first);
		$second = explode(" ", $second);
		$longestCommonSubstringIndexInFirst = 0;
		$table = array();
		$largestFound = 0;
	
		$firstLength = count($first);
		$secondLength = count($second);
		for ($i = 0; $i < $firstLength; $i++) {
			for ($j = 0; $j < $secondLength; $j++) {
				if ($first[$i] === $second[$j]) {
					if (!isset($table[$i])) {
						$table[$i] = array();
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
	public function getLongestCommonStringOfWords($words) {
		return trim(
			array_reduce(
				$words,
				[$this, 'getLongestCommonString'],
				array_shift($words)
			)
		);
	}
}
