<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'trickle',
 *		accessLevel = 'all',
 *		description = 'Shows how much skills you will gain by increasing an ability',
 *		help        = 'trickle.txt'
 *	)
 */
class TrickleController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
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
		$this->db->loadSQLFile($this->moduleName, 'trickle');
	}

	/**
	 * View trickle skills
	 *
	 * @HandlesCommand("trickle")
	 * @Matches("/^trickle( ([a-zA-Z]+) ([0-9]+)){1,6}$/i")
	 */
	public function trickle1Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$abilities = new AbilityConfig();

		$array = preg_split("/\s+/", $message);
		array_shift($array);
		for ($i = 0; isset($array[$i]); $i += 2) {
			$ability = $this->util->getAbility($array[$i]);
			if ($ability === null) {
				$msg = "Unknwon ability <highlight>{$array[$i]}<end>.";
				$sendto->reply($msg);
				return;
			}

			$abilities->$ability += (int)$array[1 + $i];
		}

		$msg = $this->processAbilities($abilities);
		$sendto->reply($msg);
	}
	
	/**
	 * View trickle skills
	 *
	 * @HandlesCommand("trickle")
	 * @Matches("/^trickle( ([0-9]+) ([a-zA-Z]+)){1,6}$/i")
	 */
	public function trickle2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$abilities = new AbilityConfig();

		$array = preg_split("/\s+/", $message);
		array_shift($array);
		for ($i = 0; isset($array[$i]); $i += 2) {
			$shortAbility = $this->util->getAbility($array[1 + $i]);
			if ($shortAbility === null) {
				$i++;
				$msg = "Unknown ability <highlight>{$array[$i]}<end>.";
				$sendto->reply($msg);
				return;
			}

			$abilities->$shortAbility += $array[$i];
		}

		$msg = $this->processAbilities($abilities);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("trickle")
	 * @Matches("/^trickle (.+)$/i")
	 */
	public function trickleSkillCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = $args[1];

		/** @var Trickle[] */
		$data = $this->db->fetchAll(
			Trickle::class,
			"SELECT * FROM trickle WHERE name LIKE ?",
			"%" . str_replace(" ", "%", $search) . "%"
		);
		$count = count($data);
		if ($count === 0) {
			$msg = "Could not find any skills for search '$search'";
		} elseif ($count === 1) {
			$msg = $this->getTrickleAmounts($data[0]);
		} else {
			$blob = "";
			foreach ($data as $row) {
				$blob .= $this->getTrickleAmounts($row) . "\n";
			}
			$msg = $this->text->makeBlob("Trickle Info: $search", $blob);
		}

		$sendto->reply($msg);
	}

	public function getTrickleAmounts(Trickle $row): string {
		$arr = ['agi', 'int', 'psy', 'sta', 'str', 'sen'];
		$msg = "<highlight>{$row->name}<end> ";
		foreach ($arr as $ability) {
			$fieldName = "amount" . ucfirst($ability);
			if ($row->$fieldName > 0) {
				$abilityName = $this->util->getAbility($ability, true);
				$value = round(4 / ($row->$fieldName), 2);
				$msg .= "($abilityName: ${value}) ";
			}
		}
		return $msg;
	}
	
	/**
	 * @return string[]
	 */
	private function processAbilities(AbilityConfig $abilities): ?array {
		$headerParts = [];
		foreach ($abilities as $short => $bonus) {
			if ($bonus > 0) {
				$headerParts []= $this->util->getAbility($short, true).
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
		$sql = "SELECT *, ".
				"( amountAgi * {$abilities->agi} ".
				"+ amountInt * {$abilities->int} ".
				"+ amountPsy * {$abilities->psy} ".
				"+ amountSta * {$abilities->sta} ".
				"+ amountStr * {$abilities->str} ".
				"+ amountSen * {$abilities->sen}) AS amount ".
			"FROM trickle ".
			"GROUP BY groupName, name, amountAgi, amountInt, amountPsy, ".
				"amountSta, amountStr, amountSen ".
			"HAVING amount > 0 ".
			"ORDER BY id";

		return $this->db->fetchAll(Trickle::class, $sql);
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

			$amount = $result->amount / 4;
			$amountInt = (int)floor($amount);
			$msg .= "<tab>" . $this->text->alignNumber($amountInt, 3, "highlight").
				".<highlight>" . substr(number_format($amount-$amountInt, 2), 2) . "<end> ".
				"<a href=skillid://{$result->skill_id}>$result->name</a>\n";
		}

		return $msg;
	}
}
