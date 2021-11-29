<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use DateTime;
use Nadybot\Core\{
	CmdContext,
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
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/what_locks.csv");
	}

	/**
	 * Search for a list of skills that can be locked and how many items lock it
	 *
	 * @HandlesCommand("whatlocks")
	 */
	public function whatLocksCommand(CmdContext $context): void {
		$query = $this->db->table("what_locks AS wl")
			->join("skills AS s", "wl.skill_id", "s.id")
			->join("aodb AS a", "wl.item_id", "a.lowid")
			->groupBy("s.name")
			->orderBy("s.name");
		$lines = $query->select("s.name", $query->rawFunc("COUNT", "*", "amount"))
			->asObj()->map(function(DBRow $row) {
				return $this->text->alignNumber((int)$row->amount, 3).
					" - ".
					$this->text->makeChatcmd($row->name, "/tell <myname> whatlocks $row->name");
			})->toArray();
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
		$context->reply($msg);
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
		$query = $this->db->table("skills");
		/** @psalm-suppress ImplicitToStringCast */
		$results = $query->where($query->colFunc("LOWER", "name"), strtolower($skill))
			->select("id", "name")->distinct()
			->asObj(Skill::class)->toArray();
		/** @var Skill[] $results */
		if (count($results) === 1) {
			return $results;
		}

		$query = $this->db->table("skills")->select("id", "name")->distinct();

		$tmp = explode(" ", $skill);
		$this->db->addWhereFromParams($query, $tmp, "name");

		return $query->asObj(Skill::class)->toArray();
	}

	/**
	 * Get a dialog to choose which skill to search for locks
	 *
	 * @param Skill[] $skills A list of skills to choose from
	 * @return string[] The complete dialogue
	 */
	public function getSkillChoiceDialog(array $skills): array {
		usort($skills, function (Skill $a, Skill $b): int {
			return strnatcmp($a->name, $b->name);
		});
		$lines = array_map(function(Skill $skill) {
			return $this->text->makeChatcmd(
				$skill->name,
				"/tell <myname> whatlocks {$skill->name}"
			);
		}, $skills);
		$msg = $this->text->makeBlob("WhatLocks - Choose Skill", join("\n", $lines));
		return (array)$msg;
	}

	/**
	 * Search for a list of items that lock a specific skill
	 *
	 * @HandlesCommand("whatlocks")
	 */
	public function whatLocksSkillCommand(CmdContext $context, string $skill): void {
		$skills = $this->searchForSkill($skill);
		if (count($skills) === 0) {
			$msg = "Could not find any skills matching <highlight>{$skill}<end>.";
			$context->reply($msg);
			return;
		} elseif (count($skills) > 1) {
			$msg = $this->getSkillChoiceDialog($skills);
			$context->reply($msg);
			return;
		}
		/** @var WhatLocks[] */
		$items = $this->db->table("what_locks AS w")
			->join("aodb AS a", "w.item_id", "a.lowid")
			->where("w.skill_id", $skills[0]->id)
			->orderBy("w.duration")
			->select("w.*", "a.*")
			->asObj(WhatLocks::class)
			->toArray();
		if (count($items) === 0) {
			$msg = "There is currently no item in the game locking ".
				"<highlight>{$skills[0]->name}<end>.";
			$context->reply($msg);
			return;
		}
		// Last element has the longest lock time, so determine how many time characters are useless
		$longestSuperfluous = $this->prettyDuration((int)$items[count($items)-1]->duration)[0];
		$lines = array_map(
			function(WhatLocks $item) use ($longestSuperfluous) {
				return $this->prettyDuration((int)$item->duration, (int)$longestSuperfluous)[1].
					" - " .
					$this->text->makeItem($item->lowid, $item->highid, $item->lowql, $item->name);
			},
			$items
		);
		$blob = join("\n<pagebreak>", $lines);
		$pages = $this->text->makeBlob(
			count($lines) . " items",
			$blob,
			"The following " . count($lines) . " items lock ". $skills[0]->name
		);
		if (is_array($pages)) {
			$msg = array_map(function($page) use ($skills) {
				return "{$page} found that lock <highlight>{$skills[0]->name}<end>.";
			}, $pages);
		} else {
			$msg =  "{$pages} found that lock <highlight>{$skills[0]->name}<end>.";
		}
		$context->reply($msg);
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
		$short = (new DateTime())->setTimestamp($duration)->format("j\\d, H\\h i\\m s\\s");
		// Decrease days by 1, because the first day of the year is 1, but for
		// duration reasons, it must be 0
		$short = preg_replace_callback(
			"/^(\d+)/",
			function(array $match): string {
				return (string)($match[1] - 1);
			},
			$short
		);
		$superfluous = strlen(preg_replace("/^([0, dhm]*).*/", "$1", $short));
		$valuable = strlen($short) - $superfluous;
		$result = "<black>" . substr($short, $cutAway, $superfluous-$cutAway) . "<end>".
			substr($short, -1 * $valuable);
		return [$superfluous, $result];
	}
}
