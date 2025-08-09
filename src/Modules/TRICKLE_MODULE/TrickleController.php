<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Collection,
	DB,
	ModuleInstance,
	Safe,
	Text,
	Types\Ability,
	Types\AccessLevel,
	Types\Skill,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HasTests,
	NCA\DefineCommand(
		command: 'trickle',
		accessLevel: AccessLevel::Guest,
		description: 'Shows how much skills you will gain by increasing an ability',
	)
]
class TrickleController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/trickle.csv');
	}

	/** Show which and how much your skills will increase by increasing all abilities by &lt;amount&gt; */
	#[NCA\HandlesCommand('trickle')]
	#[NCA\Help\Example('<symbol>trickle all 12')]
	public function trickleAllSkillsCommand(
		CmdContext $context,
		#[NCA\Parameter\Str('all')] string $attributes,
		int $amount,
	): void {
		$this->trickle1Command(
			$context,
			"str {$amount}",
			"sta {$amount}",
			"agi {$amount}",
			"sen {$amount}",
			"int {$amount}",
			"psy {$amount}",
		);
	}

	/**
	 * Show which and how much your skills will increase by increasing an ability:
	 *
	 * Valid abilities are: agi, int, psy, sta, str, sen
	 */
	#[NCA\HandlesCommand('trickle')]
	#[NCA\Help\Example('<symbol>trickle agi 4 str 4')]
	public function trickle1Command(
		CmdContext $context,
		#[NCA\Parameter\Regexp("\w+\s+\d+", example: '&lt;ability&gt; &lt;amount&gt;')] string ...$pairs
	): bool {
		if (str_starts_with($pairs[0], 'all')) {
			return false;
		}
		$abilities = new AbilityConfig();

		foreach ($pairs as $pair) {
			[$abilityName, $amount] = Safe::pregSplit("/\s+/", $pair);
			$ability = Ability::tryFromShort($abilityName);
			if ($ability === null) {
				$msg = "Unknown ability <highlight>{$abilityName}<end>.";
				$context->reply($msg);
				return true;
			}

			$abilities->add($ability, (int)$amount);
		}

		$msg = $this->processAbilities($abilities);
		$context->reply($msg);
		return true;
	}

	/**
	 * Show which and how much your skills will increase by increasing an ability:
	 *
	 * Valid abilities are: agi, int, psy, sta, str, sen
	 */
	#[NCA\HandlesCommand('trickle')]
	#[NCA\Help\Example('<symbol>trickle 5 str 10 sen')]
	public function trickle2Command(
		CmdContext $context,
		#[NCA\Parameter\Regexp("\d+\s+\w+", '&lt;amount&gt; &lt;ability&gt;')] string ...$pairs
	): void {
		$abilities = new AbilityConfig();

		foreach ($pairs as $pair) {
			[$amount, $abilityName] = Safe::pregSplit("/\s+/", $pair);
			$ability = Ability::tryFromShort($abilityName);
			if ($ability === null) {
				$msg = "Unknown ability <highlight>{$abilityName}<end>.";
				$context->reply($msg);
				return;
			}

			$abilities->add($ability, (int)$amount);
		}

		$msg = $this->processAbilities($abilities);
		$context->reply($msg);
	}

	/** See how much of each ability is needed to trickle a skill by 1 point */
	#[NCA\HandlesCommand('trickle')]
	#[NCA\Help\Example('<symbol>trickle treatment')]
	public function trickleSkillCommand(CmdContext $context, string $skill): void {
		$skills = Skill::getMatching($skill);
		$data = $this->db->table(Trickle::getTable())
			->whereIn('skill_id', array_column($skills, 'value'))
			->asObj(Trickle::class);
		$count = $data->count();
		if ($count === 0) {
			$msg = "Could not find any skills for search '{$skill}'";
		} elseif ($count === 1) {
			$msg = "To trickle 1 skill point into <highlight>{$data[0]->skill->fullName()}<end>, ".
				'you need ' . $this->getTrickleAmounts($data[0]);
		} else {
			$blob = "<header2>Required to increase skill by 1<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab><highlight>{$row->skill->fullName()}<end>: ".
					$this->getTrickleAmounts($row) . "\n";
			}
			$msg = Text::makeBlob("Trickle Info: {$skill}", $blob);
		}

		$context->reply($msg);
	}

	public function getTrickleAmounts(Trickle $row): string {
		$reqs = [];
		foreach (Ability::cases() as $ability) {
			$amount = $row->get($ability);
			if ($amount <= 0) {
				continue;
			}
			$value = round(4 / $amount, 2);
			$reqs []= "{$value} {$ability->name}";
		}
		$msg = (new Collection($reqs))->join(', ', ' or ');
		return $msg;
	}

	/** @return Collection<int,Trickle> */
	public function getTrickleResults(AbilityConfig $abilities): Collection {
		return $this->db->table(Trickle::getTable())
			->orderBy('id')
			->select('*')
			->asObj(Trickle::class)
			->filter(static function (Trickle $row) use ($abilities): bool {
				$row->amount = $row->amountAgi * $abilities->agi
					+ $row->amountInt * $abilities->int
					+ $row->amountPsy * $abilities->psy
					+ $row->amountSen * $abilities->sen
					+ $row->amountSta * $abilities->sta
					+ $row->amountStr * $abilities->str;
				return $row->amount > 0;
			});
	}

	/** @param iterable<Trickle> $results */
	public function formatOutput(iterable $results): string {
		$msg = '';
		$groupName = '';
		foreach ($results as $result) {
			if ($result->groupName !== $groupName) {
				$groupName = $result->groupName;
				$msg .= "\n<header2>{$groupName}<end>\n";
			}

			$amount = ($result->amount??0) / 4;
			$amountInt = (int)floor($amount);
			$msg .= '<tab>' . Text::alignNumber($amountInt, 3, 'highlight').
				'.<highlight>' . substr(number_format($amount-$amountInt, 2), 2) . '<end> '.
				"<a href=skillid://{$result->skill->value}>{$result->skill->inGame()}</a>\n";
		}

		return $msg;
	}

	private function processAbilities(AbilityConfig $abilities): string {
		$headerParts = [];
		$msgParts = [];

		/** @var array<string,mixed> */
		$vars = get_object_vars($abilities);
		foreach ($vars as $short => $bonus) {
			if ($bonus > 0) {
				$abiLong = Ability::tryFromShort($short)->name ?? 'Unknown ability';
				$msgParts []= "{$abiLong}: {$bonus}";
				$headerParts []= "{$abiLong}: <highlight>{$bonus}<end>";
			}
		}
		$abilitiesHeader = implode(', ', $headerParts);

		$results = $this->getTrickleResults($abilities);
		$blob = $this->formatOutput($results);
		$blob .= "\nBy Tyrence (RK2), inspired by the Bebot command of the same name";
		return Text::makeBlob(
			'Trickle Results for ' . implode(', ', $msgParts),
			$blob,
			"Trickle Results for {$abilitiesHeader}",
		);
	}
}
