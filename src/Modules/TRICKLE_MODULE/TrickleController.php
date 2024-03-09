<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "trickle",
		accessLevel: "guest",
		description: "Shows how much skills you will gain by increasing an ability",
	)
]
class TrickleController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/trickle.csv");
	}

	/** Show which and how much your skills will increase by increasing all abilities by &lt;amount&gt; */
	#[NCA\HandlesCommand("trickle")]
	#[NCA\Help\Example("<symbol>trickle all 12")]
	public function trickleAllSkillsCommand(
		CmdContext $context,
		#[NCA\Str("all")]
		string $attributes,
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
	#[NCA\HandlesCommand("trickle")]
	#[NCA\Help\Example("<symbol>trickle agi 4 str 4")]
	public function trickle1Command(
		CmdContext $context,
		#[NCA\Regexp("\w+\s+\d+", example: "&lt;ability&gt; &lt;amount&gt;")]
		string ...$pairs
	): bool {
		if (str_starts_with($pairs[0], "all")) {
			return false;
		}
		$abilities = new AbilityConfig();

		foreach ($pairs as $pair) {
			[$ability, $amount] = \Safe\preg_split("/\s+/", $pair);
			$shortAbility = $this->util->getAbility($ability);
			if ($shortAbility === null) {
				$msg = "Unknown ability <highlight>{$ability}<end>.";
				$context->reply($msg);
				return true;
			}

			$abilities->{$shortAbility} += $amount;
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
	#[NCA\HandlesCommand("trickle")]
	#[NCA\Help\Example("<symbol>trickle 5 str 10 sen")]
	public function trickle2Command(
		CmdContext $context,
		#[NCA\Regexp("\d+\s+\w+", "&lt;amount&gt; &lt;ability&gt;")]
		string ...$pairs
	): void {
		$abilities = new AbilityConfig();

		foreach ($pairs as $pair) {
			[$amount, $ability] = \Safe\preg_split("/\s+/", $pair);
			$shortAbility = $this->util->getAbility($ability);
			if ($shortAbility === null) {
				$msg = "Unknown ability <highlight>{$ability}<end>.";
				$context->reply($msg);
				return;
			}

			$abilities->{$shortAbility} += $amount;
		}

		$msg = $this->processAbilities($abilities);
		$context->reply($msg);
	}

	/** See how much of each ability is needed to trickle a skill by 1 point */
	#[NCA\HandlesCommand("trickle")]
	#[NCA\Help\Example("<symbol>trickle treatment")]
	public function trickleSkillCommand(CmdContext $context, string $skill): void {
		/** @var Collection<Trickle> */
		$data = $this->db->table("trickle")
			->whereIlike("name", "%" . str_replace(" ", "%", $skill) . "%")
			->asObj(Trickle::class);
		$count = $data->count();
		if ($count === 0) {
			$msg = "Could not find any skills for search '{$skill}'";
		} elseif ($count === 1) {
			$msg = "To trickle 1 skill point into <highlight>{$data[0]->name}<end>, ".
				"you need " . $this->getTrickleAmounts($data[0]);
		} else {
			$blob = "<header2>Required to increase skill by 1<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab><highlight>{$row->name}<end>: ".
					$this->getTrickleAmounts($row) . "\n";
			}
			$msg = $this->text->makeBlob("Trickle Info: {$skill}", $blob);
		}

		$context->reply($msg);
	}

	public function getTrickleAmounts(Trickle $row): string {
		$arr = ['agi', 'int', 'psy', 'sta', 'str', 'sen'];
		$reqs = [];
		foreach ($arr as $ability) {
			$fieldName = "amount" . ucfirst($ability);
			if ($row->{$fieldName} > 0) {
				$abilityName = $this->util->getAbility($ability, true);
				$value = round(4 / ($row->{$fieldName}), 2);
				$reqs []= "{$value} {$abilityName}";
			}
		}
		$msg = (new Collection($reqs))->join(", ", " or ");
		return $msg;
	}

	/** @return Trickle[] */
	public function getTrickleResults(AbilityConfig $abilities): array {
		return $this->db->table("trickle")
			->orderBy("id")
			->select("*")
			->asObj(Trickle::class)
			->filter(function (Trickle $row) use ($abilities): bool {
				$row->amount = $row->amountAgi * $abilities->agi
					+ $row->amountInt * $abilities->int
					+ $row->amountPsy * $abilities->psy
					+ $row->amountSen * $abilities->sen
					+ $row->amountSta * $abilities->sta
					+ $row->amountStr * $abilities->str;
				return $row->amount > 0;
			})->toArray();
	}

	/** @param Trickle[] $results */
	public function formatOutput(array $results): string {
		$msg = "";
		$groupName = "";
		foreach ($results as $result) {
			if ($result->groupName !== $groupName) {
				$groupName = $result->groupName;
				$msg .= "\n<header2>{$groupName}<end>\n";
			}

			$amount = ($result->amount??0) / 4;
			$amountInt = (int)floor($amount);
			$msg .= "<tab>" . $this->text->alignNumber($amountInt, 3, "highlight").
				".<highlight>" . substr(number_format($amount-$amountInt, 2), 2) . "<end> ".
				"<a href=skillid://{$result->skill_id}>{$result->name}</a>\n";
		}

		return $msg;
	}

	/** @return string[] */
	private function processAbilities(AbilityConfig $abilities): array {
		$headerParts = [];
		$msgParts = [];
		foreach (get_object_vars($abilities) as $short => $bonus) {
			if ($bonus > 0) {
				$msgParts []= ($this->util->getAbility($short, true) ?? "Unknown ability").
					": {$bonus}";
				$headerParts []= ($this->util->getAbility($short, true) ?? "Unknown ability").
					": <highlight>{$bonus}<end>";
			}
		}
		$abilitiesHeader = join(", ", $headerParts);

		$results = $this->getTrickleResults($abilities);
		$blob = $this->formatOutput($results);
		$blob .= "\nBy Tyrence (RK2), inspired by the Bebot command of the same name";
		return (array)$this->text->makeBlob(
			"Trickle Results for " . join(", ", $msgParts),
			$blob,
			"Trickle Results for {$abilitiesHeader}",
		);
	}
}
