<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	DBRow,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\Skill;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'whatlocks',
 *		accessLevel = 'all',
 *		description = 'List skills locked by using items',
 *		help        = 'whatlocks.txt'
 *	)
 */
class WhatLocksController {
	
	public string $moduleName;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public DB $db;

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "what_locks");
	}

	/**
	 * Search for a list of skills that can be locked and how many items lock it
	 *
	 * @HandlesCommand("whatlocks")
	 * @Matches("/^whatlocks$/i")
	 */
	public function whatLocksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT s.name,COUNT(*) AS amount ".
			"FROM what_locks wl ".
			"JOIN skills s ON wl.skill_id=s.id ".
			"JOIN aodb a ON (wl.item_id=a.lowid) ".
			"GROUP BY s.name ".
			"ORDER BY s.name ASC";
		$skills = $this->db->query($sql);
		$lines = array_map(function(DBRow $row) {
			return $this->text->alignNumber($row->amount, 3).
				" - ".
				$this->text->makeChatcmd($row->name, "/tell <myname> whatlocks $row->name");
		}, $skills);
		$blob = "<header2>Choose a skill to see which items lock it<end>\n<tab>".
			join("\n<pagebreak><tab>", $lines);
		$pages = $this->text->makeBlob(
			count($lines) . " skills that can be locked by items",
			$blob
		);
		if (is_array($pages)) {
			$msg = array_map(function($page) {
				return $page . " found.";
			}, $pages);
		} else {
			$msg =  $pages . " found.";
		}
		$sendto->reply($msg);
	}

	/**
	 * Search the skill database for a skill
	 *
	 * @param string $skill The name of the skill searched for
	 * @return Skill[] All matching skills
	 */
	public function searchForSkill(string $skill): array {
		// check for exact match first, in order to disambiguate
		// between Bow and Bow special attack
		/** @var Skill[] */
		$results = $this->db->fetchAll(
			Skill::class,
			"SELECT DISTINCT id, name FROM skills WHERE LOWER(name)=?",
			strtolower($skill)
		);
		if (count($results) === 1) {
			return $results;
		}
		
		$tmp = explode(" ", $skill);
		[$query, $params] = $this->util->generateQueryFromParams($tmp, 'name');
		
		return $this->db->fetchAll(
			Skill::class,
			"SELECT DISTINCT id, name FROM skills WHERE $query",
			...$params
		);
	}

	/**
	 * Get a dialog to choose which skill to search for locks
	 *
	 * @param Skill[] $skills A list of skills to choose from
	 * @return string The complete dialogue
	 */
	public function getSkillChoiceDialog(array $skills): string {
		$lines = array_map(function(Skill $skill) {
			return $this->text->makeChatcmd(
				$skill->name,
				"/tell <myname> whatlocks {$skill->name}"
			);
		}, $skills);
		$msg = $this->text->makeBlob("WhatLocks - Choose Skill", join("\n", $lines));
		return $msg;
	}

	/**
	 * Search for a list of items that lock a specific skill
	 *
	 * @HandlesCommand("whatlocks")
	 * @Matches("/^whatlocks\s+(.+)$/i")
	 */
	public function whatLocksSkillCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$skills = $this->searchForSkill($args[1]);
		if (count($skills) === 0) {
			$msg = "Could not find any skills matching <highlight>" . $args[1] . "<end>.";
			$sendto->reply($msg);
			return;
		} elseif (count($skills) > 1) {
			$msg = $this->getSkillChoiceDialog($skills);
			$sendto->reply($msg);
			return;
		}
		$sql = "SELECT w.*, a.* ".
			"FROM what_locks w ".
			"JOIN aodb a ON (w.item_id=a.lowid) ".
			"WHERE w.skill_id = ? ".
			"ORDER BY w.duration ASC";
		/** @var WhatLocks[] */
		$items = $this->db->fetchAll(WhatLocks::class, $sql, $skills[0]->id);
		if (count($items) === 0) {
			$msg = "There is currently no item in the game locking ".
				"<highlight>{$skills[0]->name}<end>.";
			$sendto->reply($msg);
			return;
		}
		// Last element has the longest lock time, so determine how many time characters are useless
		$longestSuperflous = $this->prettyDuration((int)$items[count($items)-1]->duration)[0];
		$lines = array_map(
			function(WhatLocks $item) use ($longestSuperflous) {
				return $this->prettyDuration((int)$item->duration, (int)$longestSuperflous)[1].
					" - " .
					$this->text->makeItem($item->lowid, $item->highid, $item->lowql, $item->name);
			},
			$items
		);
		$blob = join("\n<pagebreak>", $lines);
		$pages = $this->text->makeBlob(count($lines) . " items", $blob, "The following " . count($lines) . " items lock ". $skills[0]->name);
		if (is_array($pages)) {
			$msg = array_map(function($page) use ($skills) {
				return "{$page} found that lock <highlight>{$skills[0]->name}<end>.";
			}, $pages);
		} else {
			$msg =  "{$pages} found that lock <highlight>{$skills[0]->name}<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * Get a pretty short string of a duration in seconds
	 *
	 * @param int $duration The ducation in seconds
	 * @param int $cutAway (optional) Cut away the first $cutAway characters
	 *                                from the returned string
	 * @return array An array with 2 elements:
	 *               How many characters are useless fill information,
	 *               The prettified duration string
	 */
	public function prettyDuration(int $duration, int $cutAway=0): array {
		$short = strftime("%jd, %Hh %Mm %Ss", $duration);
		// Decrease days by 1, because the first day of the year is 1, but for
		// duration reasons, it must be 0
		$short = preg_replace_callback(
			"/^(\d+)/",
			function(array $match) {
				return $match[1] - 1;
			},
			$short
		);
		$superflous = strlen(preg_replace("/^([0, dhm]*).*/", "$1", $short));
		$valuable = strlen($short) - $superflous;
		$result = "<black>" . substr($short, $cutAway, $superflous-$cutAway) . "<end>".
			substr($short, -1 * $valuable);
		return [$superflous, $result];
	}
}
