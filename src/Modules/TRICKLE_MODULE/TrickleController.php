<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	DB,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "trickle",
		accessLevel: "all",
		description: "Shows how much skills you will gain by increasing an ability",
		help: "trickle.txt"
	)
]
class TrickleController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/trickle.csv");
	}

	/**
	 * View trickle skills
	 */
	#[NCA\HandlesCommand("trickle")]
	public function trickle1Command(CmdContext $context, #[NCA\Regexp("\w+\s+\d+(\s+\w+\s+\d+){0,5}")] string $pairs): void {
		$abilities = new AbilityConfig();

		$array = preg_split("/\s+/", $pairs);
		for ($i = 0; isset($array[$i]); $i += 2) {
			$ability = $this->util->getAbility($array[$i]);
			if ($ability === null) {
				$msg = "Unknown ability <highlight>{$array[$i]}<end>.";
				$context->reply($msg);
				return;
			}

			$abilities->$ability += (int)$array[1 + $i];
		}

		$msg = $this->processAbilities($abilities);
		$context->reply($msg);
	}

	/**
	 * View trickle skills
	 */
	#[NCA\HandlesCommand("trickle")]
	public function trickle2Command(CmdContext $context, #[NCA\Regexp("\d+\s+\w+(\s+\d+\s+\w+){0,5}")] string $pairs): void {
		$abilities = new AbilityConfig();

		$array = preg_split("/\s+/", $pairs);
		for ($i = 0; isset($array[$i]); $i += 2) {
			$shortAbility = $this->util->getAbility($array[1 + $i]);
			if ($shortAbility === null) {
				$i++;
				$msg = "Unknown ability <highlight>{$array[$i]}<end>.";
				$context->reply($msg);
				return;
			}

			$abilities->$shortAbility += $array[$i];
		}

		$msg = $this->processAbilities($abilities);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("trickle")]
	public function trickleSkillCommand(CmdContext $context, string $search): void {
		/** @var Collection<Trickle> */
		$data = $this->db->table("trickle")
			->whereIlike("name", "%" . str_replace(" ", "%", $search) . "%")
			->asObj(Trickle::class);
		$count = $data->count();
		if ($count === 0) {
			$msg = "Could not find any skills for search '$search'";
		} elseif ($count === 1) {
			$msg = "To trickle 1 skill point into <highlight>{$data[0]->name}<end>, ".
				"you need " . $this->getTrickleAmounts($data[0]);
		} else {
			$blob = "<header2>Required to increase skill by 1<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab><highlight>{$row->name}<end>: ".
					$this->getTrickleAmounts($row) . "\n";
			}
			$msg = $this->text->makeBlob("Trickle Info: $search", $blob);
		}

		$context->reply($msg);
	}

	public function getTrickleAmounts(Trickle $row): string {
		$arr = ['agi', 'int', 'psy', 'sta', 'str', 'sen'];
		$reqs = [];
		foreach ($arr as $ability) {
			$fieldName = "amount" . ucfirst($ability);
			if ($row->$fieldName > 0) {
				$abilityName = $this->util->getAbility($ability, true);
				$value = round(4 / ($row->$fieldName), 2);
				$reqs []= "{$value} {$abilityName}";
			}
		}
		$msg = (new Collection($reqs))->join(", ", " or ");
		return $msg;
	}

	/**
	 * @return string[]
	 */
	private function processAbilities(AbilityConfig $abilities): array {
		$headerParts = [];
		foreach ($abilities as $short => $bonus) {
			if ($bonus > 0) {
				$headerParts []= ($this->util->getAbility($short, true) ?? "Unknown ability").
					": <highlight>$bonus<end>";
			}
		}
		$abilitiesHeader = join(", ", $headerParts);

		$results = $this->getTrickleResults($abilities);
		$blob = $this->formatOutput($results);
		$blob .= "\nBy Tyrence (RK2), inspired by the Bebot command of the same name";
		return (array)$this->text->makeBlob("Trickle Results: $abilitiesHeader", $blob);
	}

	/**
	 * @return Trickle[]
	 */
	public function getTrickleResults(AbilityConfig $abilities): array {
		return $this->db->table("trickle")
			->orderBy("id")
			->select("*")
			->asObj(Trickle::class)
			->filter(function (Trickle $row) use ($abilities) {
				$row->amount = $row->amountAgi * $abilities->agi
					+ $row->amountInt * $abilities->int
					+ $row->amountPsy * $abilities->psy
					+ $row->amountSen * $abilities->sen
					+ $row->amountSta * $abilities->sta
					+ $row->amountStr * $abilities->str;
				return $row->amount > 0;
			})->toArray();
	}

	/**
	 * @param Trickle[] $results
	 */
	public function formatOutput(array $results): string {
		$msg = "";
		$groupName = "";
		foreach ($results as $result) {
			if ($result->groupName !== $groupName) {
				$groupName = $result->groupName;
				$msg .= "\n<header2>$groupName<end>\n";
			}

			$amount = ($result->amount??0) / 4;
			$amountInt = (int)floor($amount);
			$msg .= "<tab>" . $this->text->alignNumber($amountInt, 3, "highlight").
				".<highlight>" . substr(number_format($amount-$amountInt, 2), 2) . "<end> ".
				"<a href=skillid://{$result->skill_id}>$result->name</a>\n";
		}

		return $msg;
	}
}
