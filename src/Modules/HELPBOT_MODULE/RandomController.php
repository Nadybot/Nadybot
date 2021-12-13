<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	CommandAlias,
	DB,
	SettingManager,
	SQLException,
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
		command: "random",
		accessLevel: "all",
		description: "Randomize a list of names/items",
		help: "random.txt"
	),
	NCA\DefineCommand(
		command: "roll",
		accessLevel: "all",
		description: "Roll a random number",
		help: "roll.txt"
	),
	NCA\DefineCommand(
		command: "verify",
		accessLevel: "all",
		description: "Verifies a roll",
		help: "roll.txt"
	)
]
class RandomController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Roll");

		$this->settingManager->add(
			module: $this->moduleName,
			name: "time_between_rolls",
			description: "How much time is required between rolls from the same person",
			mode: "edit",
			type: "time",
			options: "10s;30s;60s;90s",
			value: "30s",
		);

		$this->commandAlias->register($this->moduleName, "roll heads tails", "flip");
	}

	public function canRoll(string $sender, int $timeBetweenRolls): bool {
		return $this->db->table("roll")
			->where("name", $sender)
			->where("time", ">=", time() - $timeBetweenRolls)
			->exists() === false;
	}

	#[NCA\HandlesCommand("random")]
	public function randomCommand(CmdContext $context, string $string): void {
		$items = preg_split("/(,\s+|\s+|,)/", trim($string));
		$list = [];
		while (count($items)) {
			// Pick a random item from $items and remove it
			$elem = array_splice($items, array_rand($items, 1), 1)[0];
			$list []= $elem;
		}
		$msg = "Randomized order: <highlight>" . implode("<end> -&gt; <highlight>", $list) . "<end>";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t $msg") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid $msg");
		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	#[NCA\HandlesCommand("roll")]
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
		$timeBetweenRolls = $this->settingManager->getInt('time_between_rolls')??30;
		if (!$this->canRoll($context->char->name, $timeBetweenRolls)) {
			$msg = "You can only roll once every $timeBetweenRolls seconds.";
			$context->reply($msg);
			return;
		}
		$options = [];
		for ($i = $min; $i <= $max; $i++) {
			$options []= (string)$i;
		}
		[$rollNumber, $result] = $this->roll($context->char->name, $options);
		$msg = "The roll is <highlight>$result<end> between $min and $max. To verify do /tell <myname> verify $rollNumber";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t $msg") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid $msg");

		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	/**
	 * @Mask $amount ((?:\d+)[x*])
	 */
	#[NCA\HandlesCommand("roll")]
	public function rollMultipleNamesCommand(CmdContext $context, string $amount, string $names): void {
		$amount = (int)$amount;
		$timeBetweenRolls = $this->settingManager->getInt('time_between_rolls')??30;
		if (!$this->canRoll($context->char->name, $timeBetweenRolls)) {
			$msg = "You can only roll once every $timeBetweenRolls seconds.";
			$context->reply($msg);
			return;
		}
		$options = preg_split("/(,\s+|\s+|,)/", $names);
		if ($amount > count($options)) {
			$msg = "Cannot pick more items than are on the list.";
			$context->reply($msg);
			return;
		}
		[$rollNumber, $result] = $this->roll($context->char->name, $options, $amount);
		$winners = $this->joinOptions(explode("|", $result), "highlight");
		if ($amount === 1) {
			$msg = "The winner is $winners out of the possible options ".
				$this->joinOptions($options, "highlight") . ". To verify do /tell <myname> verify $rollNumber";
		} else {
			$msg = "The winners are $winners out of the possible options ".
				$this->joinOptions($options, "highlight") . ". To verify do /tell <myname> verify $rollNumber";
		}
		$blob = $this->text->makeChatcmd("Send to team chat", "/t $msg") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid $msg");

		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	#[NCA\HandlesCommand("roll")]
	public function rollNamesCommand(CmdContext $context, string $names): void {
		$timeBetweenRolls = $this->settingManager->getInt('time_between_rolls')??30;
		if (!$this->canRoll($context->char->name, $timeBetweenRolls)) {
			$msg = "You can only roll once every $timeBetweenRolls seconds.";
			$context->reply($msg);
			return;
		}
		$options = preg_split("/(,\s+|\s+|,)/", $names);
		[$rollNumber, $result] = $this->roll($context->char->name, $options);
		$msg = "The roll is <highlight>$result<end> out of the possible options ".
			$this->joinOptions($options, "highlight") . ". To verify do /tell <myname> verify $rollNumber";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t $msg") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid $msg");

		$context->reply($this->text->blobWrap(
			$msg . " [",
			$this->text->makeBlob("announce", $blob, "Announce result"),
			"]"
		));
	}

	/**
	 * Join options in the style "A, B and C"
	 * @param string[] $options The options to join
	 * @param null|string $color If set, highlight the values with that color
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

	#[NCA\HandlesCommand("verify")]
	public function verifyCommand(CmdContext $context, int $id): void {
		/** @var ?Roll */
		$row = $this->db->table("roll")
			->where("id", $id)
			->asObj(Roll::class)
			->first();
		if ($row === null) {
			$msg = "Roll number <highlight>$id<end> does not exist.";
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
	 * @param string $sender Name of the person rolling
	 * @param string[] $options The options to roll between
	 * @return array An array with the roll number and the chosen option
	 * @throws SQLException on SQL errors
	 */
	public function roll(string $sender, array $options, int $amount=1): array {
		mt_srand();
		$result = (array)array_rand(array_flip($options), $amount);
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
}
