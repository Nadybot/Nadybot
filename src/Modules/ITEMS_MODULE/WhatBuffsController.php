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
		$blob = "<header2>Choose a skill<end>\n";
		/** @var Skill[] */
		$skills = $this->db->fetchAll(
			Skill::class,
			"SELECT DISTINCT s.name ".
			"FROM skills s ".
			"JOIN item_buffs b ON (b.attribute_id=s.id) ".
			"ORDER BY name ASC"
		);
		foreach ($skills as $skill) {
			$blob .= "<tab>" . $this->text->makeChatcmd($skill->name, "/tell <myname> {$command} $skill->name") . "\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = $this->text->makeBlob("WhatBuffs{$suffix} - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs\s+([^ ]+)$/i")
	 */
	public function whatbuffsOneWordCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = ucfirst(strtolower($this->resolveLocationAlias($args[1])));

		if ($this->verifySlot($type)) {
			$this->showSkillsBuffingType($type, false, "whatbuffs", $sendto);
			return;
		}
		$this->handleOtherComandline(false, $sendto, $args);
	}

	/**
	 * @HandlesCommand("whatbuffsfroob")
	 * @Matches("/^whatbuffsfroob\s+([^ ]+)$/i")
	 */
	public function whatbuffsFroobOneWordCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = ucfirst(strtolower($this->resolveLocationAlias($args[1])));

		if ($this->verifySlot($type)) {
			$this->showSkillsBuffingType($type, true, "whatbuffsfroob", $sendto);
			return;
		}
		$this->handleOtherComandline(true, $sendto, $args);
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
		} elseif ($type === 'Perk') {
			$sql = "SELECT s.name AS skill, COUNT(DISTINCT p.name) AS num ".
				"FROM perk p ".
				"JOIN perk_level pl ON (p.id = pl.perk_id) ".
				"JOIN perk_level_buffs plb ON (pl.id = plb.perk_level_id) ".
				"JOIN skills s ON (plb.skill_id = s.id) ".
				"WHERE (s.name IN ('SkillLockModifier', '% Add. Nano Cost') OR plb.amount > 0) ".
				"GROUP BY skill ".
				"HAVING num > 0 ".
				"ORDER BY skill ASC";
			$data = $this->db->query($sql);
		} else {
			$sql = "SELECT s.name AS skill, COUNT(1) AS num ".
				"FROM aodb a ".
				"JOIN item_types i ON a.highid = i.item_id ".
				"JOIN item_buffs b ON a.highid = b.item_id ".
				"JOIN skills s ON b.attribute_id = s.id ".
				"WHERE i.item_type = ? ".
				"AND ".$this->getItemsToExclude()." ".
				($froobFriendly ? "AND a.froob_friendly IS TRUE " : "").
				"GROUP BY skill ".
				"HAVING num > 0 ".
				"ORDER BY skill ASC";
			$data = $this->db->query($sql, $type);
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
		$tokens = explode(" ", $args[1]);
		$firstType = ucfirst(strtolower($this->resolveLocationAlias($tokens[0])));
		$lastType = ucfirst(strtolower($this->resolveLocationAlias($tokens[count($tokens) - 1])));

		if ($this->verifySlot($firstType) && !preg_match("/^smt\.?$/i", $tokens[1]??"")) {
			array_shift($tokens);
			$msg = $this->showSearchResults($firstType, join(" ", $tokens), $froobFriendly);
			$sendto->reply($msg);
			return;
		} elseif ($this->verifySlot($lastType)) {
			array_pop($tokens);
			$msg = $this->showSearchResults($lastType, join(" ", $tokens), $froobFriendly);
			$sendto->reply($msg);
			return;
		}
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
			$blob .= "<header2>Choose a skill<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab>" . $this->text->makeChatcmd(ucfirst($row->name), "/tell <myname> {$command} {$row->name}") . "\n";
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

			"UNION ALL ".

			"SELECT 'Perk' AS item_type ".
			"FROM perk_level_buffs plb ".
			"JOIN perk_level pl ON (plb.perk_Level_id = pl.id) ".
			"JOIN perk p ON (p.id = pl.perk_id) ".
			"JOIN skills s ON plb.skill_id = s.id ".
			"WHERE s.id = ? AND (s.name IN ('SkillLockModifier', '% Add. Nano Cost') OR plb.amount > 0) ".
			"GROUP BY p.name".
		") AS FOO ".
		"GROUP BY item_type ".
		"ORDER BY item_type ASC";
		$data = $this->db->query($sql, $skillId, $skillId, $skillId);
		if (count($data) === 0) {
			$msg = "There are currently no known items or nanos buffing <highlight>{$skillName}<end>";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Choose buff type<end>\n";
		foreach ($data as $row) {
			$blob .= "<tab>" . $this->text->makeChatcmd(ucfirst($row->item_type), "/tell <myname> {$command} {$row->item_type} {$skillName}") . " ($row->num)\n";
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
					"a.lowql,a.name AS use_name, s.unit ".
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
				"ORDER BY ib.amount DESC, b.name ASC";
			/** @var NanoBuffSearchResult[] */
			$data = $this->db->fetchAll(NanoBuffSearchResult::class, $sql, $skill->id);
			if (count($data) && $data[count($data) -1]->amount < 0) {
				$data = array_reverse($data);
			}
			$result = $this->formatBuffs($data, $skill);
		} elseif ($category === 'Perk') {
			$sql = "SELECT p.name,p.expansion,pl.perk_level AS perk_level, ".
				"MIN(plb.amount) AS amount, GROUP_CONCAT(plp.profession) AS profs, ".
				"s.unit AS unit ".
				"FROM perk p ".
				"JOIN perk_level pl ON (pl.perk_id=p.id) ".
				"JOIN perk_level_prof plp ON (plp.perk_level_id=pl.id) ".
				"JOIN perk_level_buffs plb ON (plb.perk_level_id=pl.id) ".
				"JOIN skills s ON plb.skill_id = s.id ".
				"WHERE s.id = ? ".
				"AND (".
					"s.name IN ('SkillLockModifier', '% Add. Nano Cost') ".
					"OR plb.amount > 0 ".
				") ".
				"GROUP BY p.name, pl.perk_level ORDER BY p.name ASC, pl.perk_level ASC, plp.profession ASC";
			/** @var PerkBuffSearchResult[] */
			$data = $this->db->fetchAll(PerkBuffSearchResult::class, $sql, $skill->id);
			/** @var array<string,PerkBuffSearchResult> */
			$result = [];
			foreach ($data as $perk) {
				if (!isset($result[$perk->name])) {
					$result[$perk->name] = $perk;
				} else {
					$result[$perk->name]->amount += $perk->amount;
				}
			}
			$data = array_values($result);
			usort(
				$data,
				function(PerkBuffSearchResult $p1, PerkBuffSearchResult $p2): int {
					return ($p2->amount <=> $p1->amount) ?: strcmp($p1->name, $p2->name);
				}
			);
			$result = $this->formatPerkBuffs($data, $skill);
		} else {
			$sql = "SELECT a.*, b.amount,b2.amount AS low_amount, wa.multi_m, wa.multi_r, s.unit ".
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
			if (count($data) && $data[count($data) -1]->amount < 0) {
				$data = array_reverse($data);
			}
			$result = $this->formatItems($data, $skill, $category);
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
		) !== null || strtolower($type) === 'perk';
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
	public function formatItems(array $items, Skill $skill, string $category) {
		$blob = "<header2>" . ucfirst($this->locationToItem($category)) . " that buff {$skill->name}<end>\n";
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
			$prefix = "<tab>" . $sign.$this->text->alignNumber(abs($item->amount), $maxDigits, 'highlight');
			$blob .= $prefix . $item->unit . "  ";
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
			"wrist" => "wrists",
		];
		return $map[$location] ?? $location;
	}
}
