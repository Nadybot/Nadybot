<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use function Safe\{preg_match_all, preg_replace, preg_split};
use InvalidArgumentException;
use Nadybot\Core\ParamClass\PItem;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	DB,
	ModuleInstance,
	SQLException,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Roll"),
	NCA\DefineCommand(
		command: "random",
		accessLevel: "guest",
		description: "Randomize a list of names/items",
	),
	NCA\DefineCommand(
		command: "roll",
		accessLevel: "guest",
		description: "Roll a random number",
	),
	NCA\DefineCommand(
		command: "verify",
		accessLevel: "all",
		description: "Verifies a roll",
	),
]
class RandomController extends ModuleInstance {
	/** How much time is required between rolls from the same person */
	#[NCA\Setting\Time(options: ["10s", "30s", "60s", "90s"])]
	public int $timeBetweenRolls = 30;
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "roll heads tails", "flip");
	}

	public function canRoll(string $sender, int $timeBetweenRolls): bool {
		return $this->db->table("roll")
			->where("name", $sender)
			->where("time", ">=", time() - $timeBetweenRolls)
			->exists() === false;
	}

	/** Randomly order a list of elements, separated by comma or space */
	#[NCA\HandlesCommand("random")]
	#[NCA\Help\Example("<symbol>random one two three")]
	#[NCA\Help\Example("<symbol>random one,two,three")]
	#[NCA\Help\Example("<symbol>random one, two, three")]
	public function randomCommand(CmdContext $context, string $elements): void {
		$items = preg_split("/(,\s+|\s+|,)/", trim($elements));
		$list = [];
		while (count($items)) {
			// Pick a random item from $items and remove it
			// @phpstan-ignore-next-line
			$elem = array_splice($items, array_rand($items, 1), 1)[0];
			$list []= $elem;
		}
		$msg = "Randomized order: <highlight>" . implode("<end> -&gt; <highlight>", $list) . "<end>";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t {$msg}") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid {$msg}");
		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	/** Roll a number between &lt;num1&gt; and &lt;num2&gt; or just 1 and &lt;num1&gt; */
	#[NCA\HandlesCommand("roll")]
	#[NCA\Help\Example("<symbol>roll 100")]
	#[NCA\Help\Example("<symbol>roll 20 30")]
	public function rollNumericCommand(CmdContext $context, int $num1, ?int $num2): void {
		if (isset($num2)) {
			$min = $num1;
			$max = $num2;
		} else {
			$min = 1;
			$max = $num1;
		}

		if ($min >= $max) {
			$msg = "The first number cannot be higher than or equal to the second number.";
			$context->reply($msg);
			return;
		}
		$timeBetweenRolls = $this->timeBetweenRolls;
		if (!$this->canRoll($context->char->name, $timeBetweenRolls)) {
			$msg = "You can only roll once every {$timeBetweenRolls} seconds.";
			$context->reply($msg);
			return;
		}
		$options = [];
		for ($i = $min; $i <= $max; $i++) {
			$options []= (string)$i;
		}
		[$rollNumber, $result] = $this->roll($context->char->name, $options);
		$msg = "The roll is <highlight>{$result}<end> between {$min} and {$max}. To verify do /tell <myname> verify {$rollNumber}";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t {$msg}") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid {$msg}");

		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	/** Roll multiple random values from a list */
	#[NCA\HandlesCommand("roll")]
	#[NCA\Help\Example("<symbol>roll 2x Andy Tim Agnes Burkhard Zara Sam")]
	public function rollMultipleNamesCommand(
		CmdContext $context,
		#[NCA\Regexp("(?:\d+)[x*]", example: "&lt;amount&gt;x")]
		string $amount,
		string $listOfNames
	): void {
		$amount = (int)$amount;
		$timeBetweenRolls = $this->timeBetweenRolls;
		if (!$this->canRoll($context->char->name, $timeBetweenRolls)) {
			$msg = "You can only roll once every {$timeBetweenRolls} seconds.";
			$context->reply($msg);
			return;
		}
		$options = [];
		$itemRegexp = PItem::getRegexp();
		preg_match_all(chr(1) . $itemRegexp . chr(1), $listOfNames, $matches);
		if (is_array($matches) && count($matches) > 0) {
			$options = $matches[0];
			$listOfNames = preg_replace(chr(1) . $itemRegexp . chr(1), "", $listOfNames);
		}

		$options = array_merge(
			$options,
			preg_split("/(,\s+|\s+|,)/", $listOfNames)
		);
		if ($amount > count($options)) {
			$msg = "Cannot pick more items than are on the list.";
			$context->reply($msg);
			return;
		}
		[$rollNumber, $result] = $this->roll($context->char->name, $options, $amount);
		$winners = $this->joinOptions(explode("|", $result), "highlight");
		if ($amount === 1) {
			$msg = "The winner is {$winners} out of the possible options ".
				$this->joinOptions($options, "highlight") . ". To verify do /tell <myname> verify {$rollNumber}";
		} else {
			$msg = "The winners are {$winners} out of the possible options ".
				$this->joinOptions($options, "highlight") . ". To verify do /tell <myname> verify {$rollNumber}";
		}
		$blob = $this->text->makeChatcmd("Send to team chat", "/t {$msg}") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid {$msg}");

		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	/** Roll a random value from a list of names */
	#[NCA\HandlesCommand("roll")]
	public function rollNamesCommand(CmdContext $context, string $listOfNames): void {
		$timeBetweenRolls = $this->timeBetweenRolls;
		if (!$this->canRoll($context->char->name, $timeBetweenRolls)) {
			$msg = "You can only roll once every {$timeBetweenRolls} seconds.";
			$context->reply($msg);
			return;
		}
		$itemRegexp = PItem::getRegexp();
		$options = [];
		preg_match_all(chr(1) . $itemRegexp . chr(1), $listOfNames, $matches);
		if (is_array($matches) && count($matches) > 0) {
			$options = $matches[0];
			$listOfNames = preg_replace(chr(1) . $itemRegexp . chr(1), "", $listOfNames);
		}

		$options = array_merge(
			$options,
			preg_split("/(,\s+|\s+|,)/", $listOfNames)
		);
		[$rollNumber, $result] = $this->roll($context->char->name, $options);
		$msg = "The roll is <highlight>{$result}<end> out of the possible options ".
			$this->joinOptions($options, "highlight") . ". To verify do /tell <myname> verify {$rollNumber}";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t {$msg}") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid {$msg}");

		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	/** Verify a roll */
	#[NCA\HandlesCommand("verify")]
	public function verifyCommand(CmdContext $context, int $rollId): void {
		/** @var ?Roll */
		$row = $this->db->table("roll")
			->where("id", $rollId)
			->asObj(Roll::class)
			->first();
		if ($row === null) {
			$msg = "Roll number <highlight>{$rollId}<end> does not exist.";
		} else {
			$options = isset($row->options) ? explode("|", $row->options) : ["&lt;none&gt;"];
			$result = isset($row->result) ? explode("|", $row->result) : ["&lt;none&gt;"];
			$time = "an unknown time";
			if (isset($row->time)) {
				$time = $this->util->unixtimeToReadable(time() - $row->time);
			}
			$msg = $this->joinOptions($result, "highlight").
				" rolled by <highlight>{$row->name}<end> {$time} ago.\n".
				"Possible options were: ".
				$this->joinOptions($options, "highlight") . ".";
		}

		$context->reply($msg);
	}

	/**
	 * Roll and record the result
	 *
	 * @param string   $sender  Name of the person rolling
	 * @param string[] $options The options to roll between
	 *
	 * @return array An array with the roll number and the chosen option
	 *
	 * @psalm-return array{0:int, 1:string}
	 *
	 * @phpstan-return array{0:int, 1:string}
	 *
	 * @throws SQLException on SQL errors
	 */
	public function roll(string $sender, array $options, int $amount=1): array {
		$revOptions = array_flip($options);
		if (empty($revOptions)) {
			throw new InvalidArgumentException("\$options to roll() must not be empty");
		}
		mt_srand();
		$result = (array)array_rand($revOptions, $amount);
		$result = implode("|", $result);
		$id = $this->db->table("roll")
			->insertGetId([
				"time" => time(),
				"name" => $sender,
				"options" => implode("|", $options),
				"result" => $result,
			]);
		return [$id, $result];
	}

	/**
	 * Join options in the style "A, B and C"
	 *
	 * @param string[]    $options The options to join
	 * @param null|string $color   If set, highlight the values with that color
	 *
	 * @return string The joined string
	 */
	protected function joinOptions(array $options, ?string $color=null): string {
		$startTag = "";
		$endTag = "";
		if ($color !== null) {
			$startTag = "<{$color}>";
			$endTag = "<end>";
		}
		$lastOption = array_pop($options);
		if (count($options)) {
			$options = [join("{$endTag}, {$startTag}", $options)];
		}
		return "{$startTag}" . join("{$endTag} and {$startTag}", [...$options, $lastOption]) . "{$endTag}";
	}
}
