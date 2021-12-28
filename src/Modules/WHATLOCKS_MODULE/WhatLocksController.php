<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use Nadybot\Core\Attributes as NCA;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	DB,
	DBRow,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Nadybot\Modules\ITEMS_MODULE\Skill;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "whatlocks",
		accessLevel: "all",
		description: "List skills locked by using items",
		help: "whatlocks.txt"
	)
]
class WhatLocksController {

	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Inject]
	public DB $db;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/what_locks.csv");
	}

	/**
	 * Search for a list of skills that can be locked and how many items lock it
	 */
	#[NCA\HandlesCommand("whatlocks")]
	public function whatLocksCommand(CmdContext $context): void {
		$query = $this->db->table("what_locks")->groupBy("skill_id");
		$skills = $query->select("skill_id", $query->rawFunc("COUNT", "*", "amount"))
			->asObj(SkillIdCount::class);
		$skillsById = $this->itemsController->getSkillByIDs(
			...$skills->pluck("skill_id")->toArray()
		)->keyBy("id");
		$lines = $skills->each(function (SkillIdCount $item) use ($skillsById): void {
			$item->skill = $skillsById->get($item->skill_id);
		})->sort(function (SkillIdCount $s1, SkillIdCount $s2): int {
			return strnatcmp($s1->skill->name, $s2->skill->name);
		})->map(function(SkillIdCount $row) {
			return $this->text->alignNumber($row->amount, 4).
				" - ".
				$this->text->makeChatcmd($row->skill->name, "/tell <myname> whatlocks {$row->skill->name}");
		});
		$blob = "<header2>Choose a skill to see which items lock it<end>\n<tab>".
			$lines->join("\n<pagebreak><tab>");
		$pages = $this->text->makeBlob(
			$lines->count() . " skills that can be locked by items",
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
	 * Get a dialog to choose which skill to search for locks
	 * @param Skill[] $skills A list of skills to choose from
	 * @return string[] The complete dialogue
	 */
	public function getSkillChoiceDialog(Skill ...$skills): array {
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
	 */
	#[NCA\HandlesCommand("whatlocks")]
	public function whatLocksSkillCommand(CmdContext $context, string $skill): void {
		$skills = $this->itemsController->searchForSkill($skill);
		if ($skills->isEmpty()) {
			$msg = "Could not find any skills matching <highlight>{$skill}<end>.";
			$context->reply($msg);
			return;
		} elseif ($skills->count() > 1) {
			$msg = $this->getSkillChoiceDialog(...$skills->toArray());
			$context->reply($msg);
			return;
		}
		/** @var Collection<WhatLocks> */
		$items = $this->db->table("what_locks")
			->where("skill_id", $skills->firstOrFail()->id)
			->orderBy("duration")
			->asObj(WhatLocks::class);
		if ($items->isEmpty()) {
			$msg = "There is currently no item in the game locking ".
				"<highlight>{$skills[0]->name}<end>.";
			$context->reply($msg);
			return;
		}
		$itemIds = $items->pluck("item_id")->filter()->toArray();
		$itemsById = $this->itemsController->getByIDs(...$itemIds)
			->keyBy("lowid");
		$items->each(function (WhatLocks $item) use ($itemsById): void {
			$item->item = $itemsById->get($item->item_id);
		});
		// Last element has the longest lock time, so determine how many time characters are useless
		$longestSuperfluous = $this->prettyDuration($items->last()->duration)[0];
		$lines = $items->map(function (WhatLocks $item) use ($longestSuperfluous): ?string {
			if (!isset($item->item)) {
				return null;
			}
			return $this->prettyDuration($item->duration, (int)$longestSuperfluous)[1].
				" - " .
				$item->item->getLink($item->item->lowql);
		});
		$blob = $lines->filter()->join("\n<pagebreak>");
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
	 * @param int $duration The ducation in seconds
	 * @param int $cutAway (optional) Cut away the first $cutAway characters
	 *                                from the returned string
	 * @return array An array with 2 elements:
	 *               How many characters are useless fill information,
	 *               The prettified duration string
	 */
	public function prettyDuration(int $duration, int $cutAway=0): array {
		$short = (new DateTime())
			->setTimestamp($duration)
			->setTimezone(new DateTimeZone("UTC"))
			->format("j\\d, H\\h i\\m s\\s");
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
