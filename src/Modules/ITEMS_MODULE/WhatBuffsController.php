<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\{
	CommandManager,
	CommandReply,
	DB,
	Http,
	LoggerWrapper,
	Text,
	Util,
};

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'whatbuffs',
 *		accessLevel = 'all',
 *		description = 'Find items or nanos that buff an ability or skill',
 *		help        = 'whatbuffs.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'whatbuffsfroob',
 *		accessLevel = 'all',
 *		alias       = 'wbf',
 *		description = 'Find froob-friendly items or nanos that buff an ability or skill',
 *		help        = 'whatbuffs.txt'
 *	)
 */
class WhatBuffsController {

	public string $moduleName;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public ItemsController $itemsController;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "item_buffs");
		$this->db->loadSQLFile($this->moduleName, "skills");
		$this->db->loadSQLFile($this->moduleName, "skill_aliases");
		$this->db->loadSQLFile($this->moduleName, "item_types");
		$this->db->loadSQLFile($this->moduleName, "buffs");
	}

	/**
	 * Get a WHERE statement (without the actual "where") which parts of aodb to ignore
	 *
	 * @return string
	 */
	public function getItemsToExclude(): string {
		$excludes = [
			"a.name != 'Brad Test Nano'",
		];
		return implode(" AND ", $excludes);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs$/i")
	 */
	public function whatbuffsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showSkillChoice($sendto, false);
	}

	/**
	 * @HandlesCommand("whatbuffsfroob")
	 * @Matches("/^whatbuffsfroobs?$/i")
	 */
	public function whatbuffsFroobCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showSkillChoice($sendto, true);
	}

	public function showSkillChoice(CommandReply $sendto, bool $froobFriendly): void {
		$command = "whatbuffs" . ($froobFriendly ? "froob" : "");
		$suffix = $froobFriendly ? "Froob" : "";
		$blob = '';
		/** @var Skill[] */
		$skills = $this->db->fetchAll(
			Skill::class,
			"SELECT DISTINCT s.name ".
			"FROM skills s ".
			"JOIN item_buffs b ON (b.attribute_id=s.id) ".
			"ORDER BY name ASC"
		);
		foreach ($skills as $skill) {
			$blob .= $this->text->makeChatcmd($skill->name, "/tell <myname> {$command} $skill->name") . "\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = $this->text->makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower)$/i")
	 */
	public function whatbuffs2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = ucfirst(strtolower($args[1]));
		$this->showSkillsBuffingType($type, false, "whatbuffs", $sendto);
	}

	/**
	 * @HandlesCommand("whatbuffsfroob")
	 * @Matches("/^whatbuffsfroobs? (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower)$/i")
	 */
	public function whatbuffsfroob2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = ucfirst(strtolower($args[1]));
		$this->showSkillsBuffingType($type, true, "whatbuffsfroob", $sendto);
	}

	public function showSkillsBuffingType(string $type, bool $froobFriendly, string $command, CommandReply $sendto): void {
		if (!$this->verifySlot($type)) {
			$msg = "Could not find any items of type <highlight>$type<end>.";
			$sendto->reply($msg);
			return;
		}
		if ($type === 'Nanoprogram') {
			$sql = "SELECT s.name AS skill, COUNT(1) AS num ".
				"FROM buffs b ".
				"JOIN item_buffs ib ON b.id = ib.item_id ".
				"JOIN skills s ON ib.attribute_id = s.id ".
				"WHERE (s.name IN ('SkillLockModifier', '% Add. Nano Cost') OR ib.amount > 0) ".
				($froobFriendly ? "AND b.froob_friendly IS TRUE " : "").
				"GROUP BY skill ".
				"HAVING num > 0 ".
				"ORDER BY skill ASC";
			$data = $this->db->query($sql);
		} else {
			$sql = "SELECT s.name AS skill, COUNT(1) AS num ".
				"FROM aodb a ".
				"JOIN item_types i ON aodb.highid = i.item_id ".
				"JOIN item_buffs b ON aodb.highid = b.item_id ".
				"JOIN skills s ON b.attribute_id = s.id ".
				"WHERE i.item_type = ? ".
				"AND ".$this->getItemsToExclude()." ".
				($froobFriendly ? "AND a.froob_friendly IS TRUE " : "").
				"GROUP BY skill ".
				"HAVING num > 0 ".
				"ORDER BY skill ASC";
			$data = $this->db->query($sql, $type);
		}
		$blob = '';
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd(ucfirst($row->skill), "/tell <myname> {$command} $type $row->skill") . " ($row->num)\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$suffix = $froobFriendly ? "Froob" : "";
		$msg = $this->text->makeBlob("WhatBuffs{$suffix} {$type} - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower) ((?!smt).+)$/i")
	 */
	public function whatbuffs3Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = $args[1];
		$skill = $args[2];

		if ($this->verifySlot($type)) {
			$msg = $this->showSearchResults($type, $skill, false);
		} else {
			$msg = "Could not find any items of type <highlight>$type<end> for skill <highlight>$skill<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffsfroob")
	 * @Matches("/^whatbuffsfroobs? (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower) ((?!smt).+)$/i")
	 */
	public function whatbuffsfroob3Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = $args[1];
		$skill = $args[2];

		if ($this->verifySlot($type)) {
			$msg = $this->showSearchResults($type, $skill, true);
		} else {
			$msg = "Could not find any items of type <highlight>$type<end> for skill <highlight>$skill<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (.+) (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower)$/i")
	 */
	public function whatbuffs4Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$skill = $args[1];
		$type = $args[2];

		if ($this->verifySlot($type)) {
			$msg = $this->showSearchResults($type, $skill, false);
		} else {
			$msg = "Could not find any items of type <highlight>$type<end> for skill <highlight>$skill<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffsfroob")
	 * @Matches("/^whatbuffsfroobs? (.+) (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower)$/i")
	 */
	public function whatbuffsfroob4Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$skill = $args[1];
		$type = $args[2];

		if ($this->verifySlot($type)) {
			$msg = $this->showSearchResults($type, $skill, true);
		} else {
			$msg = "Could not find any items of type <highlight>$type<end> for skill <highlight>$skill<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (.+)$/i")
	 */
	public function whatbuffs5Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->handleOtherComandline(false, $sendto, $args);
	}

	/**
	 * @HandlesCommand("whatbuffsfroob")
	 * @Matches("/^whatbuffsfroobs? (.+)$/i")
	 */
	public function whatbuffsfroob5Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->handleOtherComandline(true, $sendto, $args);
	}

	public function handleOtherComandline(bool $froobFriendly, CommandReply $sendto, array $args): void {
		$skill = $args[1];
		$command = "whatbuffs" . ($froobFriendly ? "froob" : "");
		$suffix = $froobFriendly ? "Froob" : "";

		$data = $this->searchForSkill($skill);
		$count = count($data);

		$blob = "";
		if ($count === 0) {
			$msg = "Could not find skill <highlight>$skill<end>.";
			$sendto->reply($msg);
			return;
		}
		if ($count > 1) {
			$blob .= "Choose a skill:\n\n";
			foreach ($data as $row) {
				$blob .= $this->text->makeChatcmd(ucfirst($row->name), "/tell <myname> {$command} {$row->name}") . "\n";
			}
			$blob .= "\nItem Extraction Info provided by AOIA+";
			$msg = $this->text->makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
			$sendto->reply($msg);
			return;
		}
		$skillId = $data[0]->id;
		$skillName = $data[0]->name;
		$sql = "SELECT item_type, COUNT(*) AS num FROM (".
			"SELECT it.item_type ".
			"FROM aodb a ".
			"JOIN item_types it ON a.highid = it.item_id ".
			"JOIN item_buffs ib ON a.highid = ib.item_id ".
			"JOIN skills s ON ib.attribute_id = s.id ".
			"WHERE s.id = ? AND (s.name IN ('SkillLockModifier', '% Add. Nano Cost') OR ib.amount > 0) ".
			($froobFriendly ? " AND a.froob_friendly IS TRUE " : "").
			"GROUP BY a.name,it.item_type,a.lowql,a.highql,ib.amount ".

			"UNION ALL ".

			"SELECT 'Nanoprogram' AS item_type ".
			"FROM buffs b ".
			"JOIN item_buffs ib ON ib.item_id = b.id ".
			"JOIN skills s ON ib.attribute_id = s.id ".
			"WHERE s.id = ? AND (s.name IN ('SkillLockModifier', '% Add. Nano Cost') OR ib.amount > 0) ".
			($froobFriendly ? " AND b.froob_friendly IS TRUE " : "").
		") AS FOO ".
		"GROUP BY item_type ".
		"ORDER BY item_type ASC";
		$data = $this->db->query($sql, $skillId, $skillId);
		if (count($data) === 0) {
			$msg = "There are currently no known items or nanos buffing <highlight>{$skillName}<end>";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd(ucfirst($row->item_type), "/tell <myname> {$command} {$row->item_type} {$skillName}") . " ($row->num)\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = $this->text->makeBlob("WhatBuffs{$suffix} {$skillName} - Choose Type", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Gives a blob with all items buffing $skill in slot $category
	 * @return string|string[]
	 */
	public function getSearchResults(string $category, Skill $skill, bool $froobFriendly) {
		$suffix = $froobFriendly ? "Froob" : "";
		if ($category === 'Nanoprogram') {
			$sql = "SELECT b.*, ib.amount, a.lowid, a.highid, ".
					"a.lowql,a.name AS use_name ".
				"FROM buffs b ".
				"JOIN item_buffs ib ON b.id = ib.item_id ".
				"JOIN skills s ON ib.attribute_id = s.id ".
				"LEFT JOIN aodb a ON (a.lowid=b.use_id) ".
				"WHERE s.id = ? ".
				"AND (".
					"s.name IN ('SkillLockModifier', '% Add. Nano Cost') ".
					"OR ib.amount > 0 ".
				") ".
				"AND b.name NOT IN (".
					"'Ineptitude Transfer', ".
					"'Accumulated Interest', ".
					"'Unforgiven Debts', ".
					"'Payment Plan' ".
				") ".
				($froobFriendly ? "AND b.froob_friendly IS TRUE " : "").
				"ORDER BY ABS(ib.amount) DESC, b.name ASC";
			/** @var NanoBuffSearchResult[] */
			$data = $this->db->fetchAll(NanoBuffSearchResult::class, $sql, $skill->id);
			$result = $this->formatBuffs($data);
		} else {
			$sql = "SELECT a.*, b.amount,b2.amount AS low_amount, wa.multi_m, wa.multi_r ".
				"FROM aodb a ".
				"JOIN item_types i ON a.highid = i.item_id ".
				"JOIN item_buffs b ON a.highid = b.item_id ".
				"LEFT JOIN item_buffs b2 ON a.lowid = b2.item_id ".
				"LEFT JOIN weapon_attributes wa ON a.highid = wa.id ".
				"JOIN skills s ON b.attribute_id = s.id AND b2.attribute_id = s.id ".
				"WHERE i.item_type = ? AND s.id = ? AND (s.name IN ('SkillLockModifier', '% Add. Nano Cost') OR b.amount > 0) ".
				"AND ".$this->getItemsToExclude()." ".
				($froobFriendly ? "AND a.froob_friendly IS TRUE " : "").
				"GROUP BY a.name,a.lowql,a.highql,b.amount,b2.amount,wa.multi_m,wa.multi_r ".
				"ORDER BY ABS(b.amount) DESC, name DESC";
			/** @var ItemBuffSearchResult[] */
			$data = $this->db->fetchAll(ItemBuffSearchResult::class, $sql, $category, $skill->id);
			$result = $this->formatItems($data, $skill);
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

	/**
	 * Check if a slot (fingers, chest) exists
	 */
	public function verifySlot(string $type): bool {
		return $this->db->queryRow(
			"SELECT 1 FROM item_types WHERE item_type = ? LIMIT 1",
			ucfirst(strtolower($type))
		) !== null;
	}

	/**
	 * Search for all skills and skill aliases matching $skill
	 *
	 * @return Skill[]
	 */
	public function searchForSkill(string $skill): array {
		// check for exact match first, in order to disambiguate
		// between Bow and Bow special attack
		/** @var Skill[] */
		$results = $this->db->fetchAll(
			Skill::class,
			"SELECT DISTINCT id, name FROM skills WHERE name LIKE ? ".
			" UNION ".
			"SELECT DISTINCT a.id, s.name FROM skill_alias a JOIN skills s USING(id) WHERE a.name LIKE ?",
			$skill,
			$skill
		);
		if (count($results) === 1) {
			return $results;
		}

		$tmp = explode(" ", $skill);
		[$query, $params] = $this->util->generateQueryFromParams($tmp, 'name');

		return $this->db->fetchAll(
			Skill::class,
			"SELECT id, name FROM ( ".
				"SELECT DISTINCT id, name FROM skills WHERE $query ".
				"UNION ".
				"SELECT DISTINCT id, name FROM skill_alias WHERE $query ".
			") AS foo GROUP BY id ORDER BY name ASC",
			...[...$params, ...$params]
		);
	}

	public function showItemLink(\Nadybot\Core\DBRow $item, $ql) {
		return $this->text->makeItem($item->lowid, $item->highid, $ql, $item->name);
	}

	/**
	 * Format a list of item buff search results
	 * @param ItemBuffSearchResult[] $items The items that matched the search
	 * @return (int|string)[]
	 */
	public function formatItems(array $items, Skill $skill) {
		$blob = '';
		$maxBuff = 0;
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
				$item->amount = $this->util->interpolate($item->lowql, $item->highql, $item->low_amount, $item->amount, 250);
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
			$prefix = $sign.$this->text->alignNumber(abs($item->amount), $maxDigits, 'highlight');
			$blob .= $prefix . "  ";
			if ($item->multi_m !== null || $item->multi_r !== null) {
				$blob .= "2x ";
			}
			/* $blob .= $this->showItemLink($item->lowid, $item->highid, $item->highql, $item->name); */
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
			$blob .= "\n";
		}

		$count = count($items);
		return [$count, $blob];
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
	 * @param NanoBuffSearchResult[] $items
	 * @return (int|string)[]
	 */
	public function formatBuffs(array $items) {
		$blob = '';
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
			$prefix = $this->text->alignNumber($item->amount, $maxDigits, 'highlight');
			$blob .= $prefix . "  <a href='itemid://53019/{$item->id}'>{$item->name}</a> ";
			if (isset($item->low_ncu) && isset($item->low_amount)) {
				$blob .= "($item->low_ncu NCU (<highlight>$item->low_amount<end>) - $item->ncu NCU (<highlight>$item->amount<end>))";
			} else {
				$blob .= "($item->ncu NCU)";
			}
			if ($item->lowid > 0) {
				$blob .= " (from " . $this->text->makeItem($item->lowid, $item->highid, $item->lowql, $item->use_name) . ")";
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
}
