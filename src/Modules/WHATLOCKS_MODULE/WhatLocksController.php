<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use DateTimeZone;
use Nadybot\Core\Exceptions\UserException;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Safe,
	Text,
};
use Nadybot\Modules\ITEMS_MODULE\{ItemsController, Skill};

use Safe\DateTimeImmutable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'whatlocks',
		accessLevel: 'guest',
		description: 'List skills locked by using items',
	)
]
class WhatLocksController extends ModuleInstance {
	#[NCA\Inject]
	private ItemsController $itemsController;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/what_locks.csv');
	}

	/** Search for a list of skills that can be locked and how many items lock it */
	#[NCA\HandlesCommand('whatlocks')]
	public function whatLocksCommand(CmdContext $context): void {
		$query = $this->db->table(WhatLocks::getTable())->groupBy('skill_id');
		$skills = $query->select(['skill_id', $query->raw($query->rawFunc('COUNT', '*', 'amount'))])
			->get();
		$skillsById = $this->itemsController->getSkillByIDs(
			...$skills->pluck('skill_id')->toArray()
		)->keyBy('id');
		$lines = $skills->map(static function (\stdClass $item) use ($skillsById): SkillIdCount {
			$skill = $skillsById->get($item->skill_id);
			if (!isset($skill)) {
				throw new UserException('Unknown skill encountered');
			}
			return new SkillIdCount(
				skill_id: $item->skill_id,
				amount: $item->amount,
				skill: $skill,
			);
		})->sort(static function (SkillIdCount $s1, SkillIdCount $s2): int {
			return strnatcmp($s1->skill->name, $s2->skill->name);
		})->map(static function (SkillIdCount $row): string {
			return Text::alignNumber($row->amount, 4).
				' - '.
				Text::makeChatcmd($row->skill->name, "/tell <myname> whatlocks {$row->skill->name}");
		});
		$blob = "<header2>Choose a skill to see which items lock it<end>\n<tab>".
			$lines->join("\n<pagebreak><tab>");
		$pages = Text::makeBlob(
			$lines->count() . ' skills that can be locked by items',
			$blob
		);
		$msg = "{$pages} found.";
		$context->reply($msg);
	}

	/**
	 * Get a dialog to choose which skill to search for locks
	 *
	 * @param Skill $skills A list of skills to choose from
	 *
	 * @return list<string> The complete dialogue
	 */
	public function getSkillChoiceDialog(Skill ...$skills): array {
		usort($skills, static function (Skill $a, Skill $b): int {
			return strnatcmp($a->name, $b->name);
		});
		$lines = array_map(static function (Skill $skill): string {
			return Text::makeChatcmd(
				$skill->name,
				"/tell <myname> whatlocks {$skill->name}"
			);
		}, $skills);
		$msg = Text::makeBlob('WhatLocks - Choose Skill', implode("\n", $lines));
		return (array)$msg;
	}

	/** Search for a list of items that lock a specific skill */
	#[NCA\HandlesCommand('whatlocks')]
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

		$items = $this->db->table(WhatLocks::getTable())
			->where('skill_id', $skills->firstOrFail()->id)
			->orderBy('duration')
			->asObj(WhatLocks::class);
		if ($items->isEmpty()) {
			$msg = 'There is currently no item in the game locking '.
				"<highlight>{$skills[0]->name}<end>.";
			$context->reply($msg);
			return;
		}
		$itemIds = $items->pluck('item_id')->filter()->toArray();
		$itemsById = $this->itemsController->getByIDs(...$itemIds)
			->keyBy('lowid');
		$items->each(static function (WhatLocks $item) use ($itemsById): void {
			$item->item = $itemsById->get($item->item_id);
		});
		$lastItem = $items->last();
		assert(isset($lastItem));
		// Last element has the longest lock time, so determine how many time characters are useless
		$longestSuperfluous = $this->prettyDuration($lastItem->duration)[0];
		$lines = $items->map(function (WhatLocks $item) use ($longestSuperfluous): ?string {
			if (!isset($item->item)) {
				return null;
			}
			return $this->prettyDuration($item->duration, $longestSuperfluous)[1].
				' - ' .
				$item->item->getLink($item->item->lowql);
		});
		$blob = $lines->filter()->join("\n<pagebreak>");
		$pages = Text::makeBlob(
			count($lines) . ' items',
			$blob,
			'The following ' . count($lines) . ' items lock '. $skills[0]->name
		);
		$msg =  "{$pages} found that lock <highlight>{$skills[0]->name}<end>.";
		$context->reply($msg);
	}

	/**
	 * Get a pretty short string of a duration in seconds
	 *
	 * @param int $duration The duration in seconds
	 * @param int $cutAway  (optional) Cut away the first $cutAway characters
	 *                      from the returned string
	 *
	 * @return array An array with 2 elements:
	 *               How many characters are useless fill information,
	 *               The prettified duration string
	 *
	 * @phpstan-return array{int, string}
	 */
	public function prettyDuration(int $duration, int $cutAway=0): array {
		$short = (new DateTimeImmutable(timezone: new DateTimeZone('UTC')))
			->setTimestamp($duration)
			->format('j\\d, H\\h i\\m s\\s');
		// Decrease days by 1, because the first day of the year is 1, but for
		// duration reasons, it must be 0
		$short = Safe::pregReplaceCallback(
			"/^(\d+)/",
			static function (array $match): string {
				return (string)((int)$match[1] - 1);
			},
			$short
		);
		$superfluous = strlen(Safe::pregReplace('/^([0, dhm]*).*/', '$1', $short));
		$valuable = strlen($short) - $superfluous;
		$result = '<black>' . substr($short, $cutAway, $superfluous-$cutAway) . '<end>'.
			substr($short, -1 * $valuable);
		return [$superfluous, $result];
	}
}
