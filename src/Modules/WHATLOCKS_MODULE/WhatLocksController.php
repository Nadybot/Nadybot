<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use DateTimeZone;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Safe,
	Text,
	Types\AccessLevel,
	Types\Skill,
};
use Nadybot\Core\Exceptions\UserException;
use Nadybot\Modules\ITEMS_MODULE\ItemsController;

use Safe\DateTimeImmutable;
use Throwable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HasTests,
	NCA\DefineCommand(
		command: 'whatlocks',
		accessLevel: AccessLevel::Guest,
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

		try {
			$lines = $query->select(
				['skill_id AS skill', $query->raw($query->rawFunc('COUNT', '*', 'amount'))]
			)->whereNotNull('skill_id')
			->asObj(SkillCount::class)
			->sortUsing(static fn (SkillCount $s): string => $s->skill->fullName())
			->map(static function (SkillCount $row): string {
				return Text::alignNumber($row->amount, 4).
					' - '.
					Text::makeChatcmd($row->skill->fullName(), "/tell <myname> whatlocks {$row->skill->fullName()}");
			});
		} catch (Throwable $e) {
			throw new UserException(message: 'Unknown skill found', previous: $e);
		}
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
			return strnatcmp($a->fullName(), $b->fullName());
		});
		$lines = array_map(static function (Skill $skill): string {
			return Text::makeChatcmd(
				$skill->fullName(),
				"/tell <myname> whatlocks {$skill->fullName()}"
			);
		}, $skills);
		$msg = Text::makeBlob('WhatLocks - Choose Skill', implode("\n", $lines));
		return (array)$msg;
	}

	/** Search for a list of items that lock a specific skill */
	#[NCA\HandlesCommand('whatlocks')]
	public function whatLocksSkillCommand(CmdContext $context, string $skill): void {
		$skills = Skill::getMatching($skill);
		if (!count($skills)) {
			$msg = "Could not find any skills matching <highlight>{$skill}<end>.";
			$context->reply($msg);
			return;
		} elseif (count($skills) > 1) {
			$msg = $this->getSkillChoiceDialog(...$skills);
			$context->reply($msg);
			return;
		}

		$items = $this->db->table(WhatLocks::getTable())
			->where('skill_id', $skills[0]->value)
			->orderBy('duration')
			->asObj(WhatLocks::class);
		if ($items->isEmpty()) {
			$msg = 'There is currently no item in the game locking '.
				"<highlight>{$skills[0]->fullName()}<end>.";
			$context->reply($msg);
			return;
		}

		$itemIds = $items->whereNotNull('item_id')->pluckInts('item_id')->toList();
		$itemsById = $this->itemsController->getByIDs(...$itemIds)
			->keyByInt('lowid');
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
		$blob = $lines->filterNull()->join("\n<pagebreak>");
		$pages = Text::makeBlob(
			count($lines) . ' items',
			$blob,
			'The following ' . count($lines) . ' items lock '. $skills[0]->fullName()
		);
		$msg =  "{$pages} found that lock <highlight>{$skills[0]->fullName()}<end>.";
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
