<?php

namespace Budabot\Modules\ITEMS_MODULE;

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
 */
class WhatBuffsController {
	
	public $moduleName;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * @var \Budabot\Core\CommandAlias $commandAlias
	 * @Inject
	 */
	public $commandAlias;

	/**
	 * @var \Budabot\Core\CommandManager $commandManager
	 * @Inject
	 */
	public $commandManager;
	
	/**
	 * @var \Budabot\Modules\ITEMS_MODULE\ItemsController $itemsController
	 * @Inject
	 */
	public $itemsController;
	
	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;
	
	/** @Setup */
	public function setup() {
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
	public function getItemsToExclude() {
		$excludes = array(
			"aodb.name != 'Brad Test Nano'",
		);
		return implode(" AND ", $excludes);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs$/i")
	 */
	public function whatbuffsCommand($message, $channel, $sender, $sendto, $args) {
		$blob = '';
		$data = $this->db->query(
			"SELECT
				DISTINCT s.name
			FROM
				skills s
			JOIN
				item_buffs b ON (b.attribute_id=s.id)
			ORDER BY
				name ASC"
		);
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd($row->name, "/tell <myname> whatbuffs $row->name") . "\n";
		}
		$blob .= "\nItem Extraction Info provided by AOIA+";
		$msg = $this->text->makeBlob("WhatBuffs - Choose Skill", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower)$/i")
	 */
	public function whatbuffs2Command($message, $channel, $sender, $sendto, $args) {
		$type = ucfirst(strtolower($args[1]));
		
		if ($this->verifySlot($type)) {
			if ($type === 'Nanoprogram') {
				$sql = "
					SELECT s.name AS skill, COUNT(1) AS num
					FROM buffs b
					JOIN item_buffs ib ON b.id = ib.item_id
					JOIN skills s ON ib.attribute_id = s.id
					WHERE (s.name='SkillLockModifier' OR ib.amount > 0)
					GROUP BY skill
					HAVING num > 0
					ORDER BY skill ASC";
				$data = $this->db->query($sql);
			} else {
				$sql = "
					SELECT s.name AS skill, COUNT(1) AS num
					FROM aodb
					JOIN item_types i ON aodb.highid = i.item_id
					JOIN item_buffs b ON aodb.highid = b.item_id
					JOIN skills s ON b.attribute_id = s.id
					WHERE i.item_type = ?
					AND ".$this->getItemsToExclude()."
					GROUP BY skill
					HAVING num > 0
					ORDER BY skill ASC";
				$data = $this->db->query($sql, $type);
			}
			$blob = '';
			foreach ($data as $row) {
				$blob .= $this->text->makeChatcmd(ucfirst($row->skill), "/tell <myname> whatbuffs $type $row->skill") . " ($row->num)\n";
			}
			$blob .= "\nItem Extraction Info provided by Unk";
			$msg = $this->text->makeBlob("WhatBuffs $type - Choose Skill", $blob);
		} else {
			$msg = "Could not find any items of type <highlight>$type<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower) ((?!smt).+)$/i")
	 */
	public function whatbuffs3Command($message, $channel, $sender, $sendto, $args) {
		$type = $args[1];
		$skill = $args[2];

		if ($this->verifySlot($type)) {
			$msg = $this->showSearchResults($type, $skill);
		} else {
			$msg = "Could not find any items of type <highlight>$type<end> for skill <highlight>$skill<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (.+) (arms|back|chest|deck|feet|fingers|hands|head|hud|legs|nanoprogram|neck|shoulders|unknown|util|weapon|wrists|use|contract|tower)$/i")
	 */
	public function whatbuffs4Command($message, $channel, $sender, $sendto, $args) {
		$skill = $args[1];
		$type = $args[2];

		if ($this->verifySlot($type)) {
			$msg = $this->showSearchResults($type, $skill);
		} else {
			$msg = "Could not find any items of type <highlight>$type<end> for skill <highlight>$skill<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whatbuffs")
	 * @Matches("/^whatbuffs (.+)$/i")
	 */
	public function whatbuffs5Command($message, $channel, $sender, $sendto, $args) {
		$skill = $args[1];

		$data = $this->searchForSkill($skill);
		$count = count($data);

		$blob = "";
		if ($count == 0) {
			$msg = "Could not find skill <highlight>$skill<end>.";
		} elseif ($count > 1) {
			$blob .= "Choose a skill:\n\n";
			foreach ($data as $row) {
				$blob .= $this->text->makeChatcmd(ucfirst($row->name), "/tell <myname> whatbuffs $row->name") . "\n";
			}
			$blob .= "\nItem Extraction Info provided by Unk";
			$msg = $this->text->makeBlob("WhatBuffs - Choose Skill", $blob);
		} else {
			$skillId = $data[0]->id;
			$skillName = $data[0]->name;
			$sql = "
			SELECT item_type, COUNT(*) AS num FROM (
				SELECT it.item_type
				FROM aodb a
				JOIN item_types it ON a.highid = it.item_id
				JOIN item_buffs ib ON a.highid = ib.item_id
				JOIN skills s ON ib.attribute_id = s.id
				WHERE s.id = ? AND (s.name='SkillLockModifier' OR ib.amount > 0)
				GROUP BY a.name,it.item_type,a.lowql,a.highql,ib.amount

				UNION ALL

				SELECT 'Nanoprogram' AS item_type
				FROM buffs b
				JOIN item_buffs ib ON ib.item_id = b.id
				JOIN skills s ON ib.attribute_id = s.id
				WHERE s.id = ? AND (s.name='SkillLockModifier' OR ib.amount > 0)
			) AS FOO
			GROUP BY item_type
			ORDER BY item_type ASC
			";
			$data = $this->db->query($sql, $skillId, $skillId);
			if (count($data) === 0) {
				$msg = "There are currently no known items or nanos buffing <highlight>{$skillName}<end>";
				$sendto->reply($msg);
				return;
			}
			$blob = '';
			foreach ($data as $row) {
				$blob .= $this->text->makeChatcmd(ucfirst($row->item_type), "/tell <myname> whatbuffs $row->item_type $skillName") . " ($row->num)\n";
			}
			$blob .= "\nItem Extraction Info provided by Unk";
			$msg = $this->text->makeBlob("WhatBuffs $skillName - Choose Type", $blob);
		}
		$sendto->reply($msg);
	}
	
	public function getSearchResults($category, $skill) {
		if ($category === 'Nanoprogram') {
			$sql = "
				SELECT buffs.*, b.amount,aodb.lowid,aodb.highid,aodb.lowql,aodb.name AS use_name
				FROM buffs
				JOIN item_buffs b ON buffs.id = b.item_id
				JOIN skills s ON b.attribute_id = s.id
				LEFT JOIN aodb ON (aodb.lowid=buffs.use_id)
				WHERE s.id = ? AND (s.name='SkillLockModifier' OR b.amount > 0) AND buffs.name NOT IN ('Ineptitude Transfer', 'Accumulated Interest', 'Unforgiven Debts', 'Payment Plan')
				ORDER BY b.amount DESC, buffs.name ASC
			";
			$data = $this->db->query($sql, $skill->id);
			$result = $this->formatBuffs($data);
		} else {
			$sql = "
				SELECT aodb.*, b.amount,b2.amount AS low_amount, wa.multi_m, wa.multi_r
				FROM aodb
				JOIN item_types i ON aodb.highid = i.item_id
				JOIN item_buffs b ON aodb.highid = b.item_id
				LEFT JOIN item_buffs b2 ON aodb.lowid = b2.item_id
				LEFT JOIN weapon_attributes wa ON aodb.highid = wa.id
				JOIN skills s ON b.attribute_id = s.id AND b2.attribute_id = s.id
				WHERE i.item_type = ? AND s.id = ? AND (s.name='SkillLockModifier' OR b.amount > 0)
				AND ".$this->getItemsToExclude()."
				GROUP BY aodb.name,aodb.lowql,aodb.highql,b.amount,b2.amount,wa.multi_m,wa.multi_r
				ORDER BY b.amount DESC, name DESC
			";
			$data = $this->db->query($sql, $category, $skill->id);
			$result = $this->formatItems($data);
		}

		if ($result === null) {
			$msg = "No items found of type <highlight>$category<end> that buff <highlight>$skill->name<end>.";
		} else {
			list($count, $blob) = $result;
			$blob .= "\nItem Extraction Info provided by Unk";
			$msg = $this->text->makeBlob("WhatBuffs - $category $skill->name ($count)", $blob);
		}
		return $msg;
	}

	public function verifySlot($type) {
		$type = ucfirst(strtolower($type));
		$row = $this->db->queryRow("SELECT 1 FROM item_types WHERE item_type = ? LIMIT 1", $type);
		return $row !== null;
	}
	
	public function searchForSkill($skill) {
		// check for exact match first, in order to disambiguate
		// between Bow and Bow special attack
		$results = $this->db->query(
			"SELECT DISTINCT id, name FROM skills WHERE name LIKE ? ".
			" UNION ".
			"SELECT DISTINCT a.id, s.name FROM skill_alias a JOIN skills s USING(id) WHERE a.name LIKE ?",
			$skill,
			$skill
		);
		if (count($results) == 1) {
			return $results;
		}
		
		$tmp = explode(" ", $skill);
		list($query, $params) = $this->util->generateQueryFromParams($tmp, 'name');
		
		return $this->db->query(
			"SELECT id, name FROM (
				SELECT DISTINCT id, name FROM skills WHERE $query
				UNION
				SELECT DISTINCT id, name FROM skill_alias WHERE $query
			) AS foo GROUP BY id ORDER BY name ASC",
			array_merge($params, $params)
		);
	}

	public function showItemLink(\Budabot\Core\DBRow $item, $ql) {
		return $this->text->makeItem($item->lowid, $item->highid, $ql, $item->name);
	}

	public function formatItems($items) {
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
			$maxBuff = max($maxBuff, $item->amount);
			if ($item->lowid == $item->highid) {
				$itemMapping[$item->lowid] = $item;
			}
		}
		$ignoreItems = array();
		foreach ($items as $item) {
			if ($item->highid != $item->lowid && array_key_exists($item->highid, $itemMapping)) {
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
			$prefix = $this->text->alignNumber($item->amount, $maxDigits, 'highlight');
			$blob .= $prefix . "  ";
			if ($item->multi_m !== null || $item->multi_r !== null) {
				$blob .= "2x ";
			}
			/* $blob .= $this->showItemLink($item->lowid, $item->highid, $item->highql, $item->name); */
			$blob .= $this->showItemLink($item, $item->highql);
			if ($item->amount > $item->low_amount) {
				$blob .= " ($item->low_amount - $item->amount)";
				if ($this->commandManager->get('bestql')) {
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
		if ($count > 0) {
			return array($count, $blob);
		} else {
			return null;
		}
	}

	public function groupDrainsAndWrangles($items) {
		$result = array();
		$groups = array(
			'/(Divest|Deprive) Skills.*Transfer/',
			'/(Ransack|Plunder) Skills.*Transfer/',
			'/^Umbral Wrangler/',
			'/^Team Skill Wrangler/',
			'/^Skill Wrangler/',
		);
		$highestOfGroup = array();
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

	public function formatBuffs($items) {
		$blob = '';
		$maxBuff = 0;
		foreach ($items as $item) {
			$maxBuff = max($maxBuff, $item->amount);
		}
		$maxDigits = strlen((string)$maxBuff);
		$items = $this->groupDrainsAndWrangles($items);
		foreach ($items as $item) {
			if ($item->ncu == 999) {
				$item->ncu = 0;
			}
			$prefix = $this->text->alignNumber($item->amount, $maxDigits, 'highlight');
			$blob .= $prefix . "  <a href='itemid://53019/{$item->id}'>{$item->name}</a> ";
			if (isset($item->low_ncu)) {
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
		if ($count > 0) {
			return array($count, $blob);
		} else {
			return null;
		}
	}
	
	public function showSearchResults($category, $skill) {
		$category = ucfirst(strtolower($category));
		
		$data = $this->searchForSkill($skill);
		$count = count($data);
		
		if ($count == 0) {
			$msg = "Could not find any skills matching <highlight>$skill<end>.";
		} elseif ($count == 1) {
			$row = $data[0];
			$msg = $this->getSearchResults($category, $row);
		} else {
			$blob = '';
			foreach ($data as $row) {
				$blob .= $this->text->makeChatcmd(ucfirst($row->skill), "/tell <myname> whatbuffs $category $row->skill") . "\n";
			}
			$msg = $this->text->makeBlob("WhatBuffs - Choose Skill", $blob);
		}
		
		return $msg;
	}
}
