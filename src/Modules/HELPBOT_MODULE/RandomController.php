<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	DB,
	SettingManager,
	SQLException,
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
 *		command     = 'random',
 *		accessLevel = 'all',
 *		description = 'Randomize a list of names/items',
 *		help        = 'random.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'roll',
 *		accessLevel = 'all',
 *		description = 'Roll a random number',
 *		help        = 'roll.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'verify',
 *		accessLevel = 'all',
 *		description = 'Verifies a roll',
 *		help        = 'roll.txt'
 *	)
 */
class RandomController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public CommandAlias $commandAlias;
	
	/**
	 * @Setting("time_between_rolls")
	 * @Description("How much time is required between rolls from the same person")
	 * @Visibility("edit")
	 * @Type("time")
	 * @Options("10s;30s;60s;90s")
	 */
	public string $defaultTimeBetweenRolls = "30s";
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'roll');
		
		$this->commandAlias->register($this->moduleName, "roll heads tails", "flip");
	}
	
	/**
	 * @HandlesCommand("random")
	 * @Matches("/^random (.+)$/i")
	 */
	public function randomCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$items = preg_split("/(,\s+|\s+|,)/", trim($args[1]));
		while (count($items)) {
			// Pick a random item from $items and remove it
			$elem = array_splice($items, array_rand($items, 1), 1)[0];
			$list []= $elem;
		}
		$msg = "Randomized order: <highlight>" . implode("<end> -&gt; <highlight>", $list) . "<end>";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t $msg") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid $msg");
		$sendto->reply($msg . " [" . $this->text->makeBlob("announce", $blob, "Announce result") . "]");
	}

	/**
	 * @HandlesCommand("roll")
	 * @Matches("/^roll ([0-9]+)$/i")
	 * @Matches("/^roll ([0-9]+) ([0-9]+)$/i")
	 */
	public function rollNumericCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (count($args) == 3) {
			$min = (int)$args[1];
			$max = (int)$args[2];
		} else {
			$min = 1;
			$max = (int)$args[1];
		}

		if ($min >= $max) {
			$msg = "The first number cannot be higher than or equal to the second number.";
			$sendto->reply($msg);
			return;
		}
		$timeBetweenRolls = $this->settingManager->getInt('time_between_rolls');
		/** @var ?Roll */
		$row = $this->db->fetch(Roll::class, "SELECT * FROM roll WHERE `name` = ? AND `time` >= ? LIMIT 1", $sender, time() - $timeBetweenRolls);
		if ($row !== null) {
			$msg = "You can only roll once every $timeBetweenRolls seconds.";
			$sendto->reply($msg);
			return;
		}
		$options = [];
		for ($i = $min; $i <= $max; $i++) {
			$options []= $i;
		}
		[$rollNumber, $result] = $this->roll($sender, $options);
		$msg = "The roll is <highlight>$result<end> between $min and $max. To verify do /tell <myname> verify $rollNumber";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t $msg") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid $msg");

		$sendto->reply($msg . " [" . $this->text->makeBlob("announce", $blob, "Announce result") . "]");
	}

	/**
	 * @HandlesCommand("roll")
	 * @Matches("/^roll (\d+)[x*]\s+(.+)$/i")
	 */
	public function rollMultipleNamesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$amount = (int)$args[1];
		$names = $args[2];
		$timeBetweenRolls = $this->settingManager->getInt('time_between_rolls');
		$row = $this->db->fetch(Roll::class, "SELECT * FROM roll WHERE `name` = ? AND `time` >= ? LIMIT 1", $sender, time() - $timeBetweenRolls);
		if ($row !== null) {
			$msg = "You can only roll once every $timeBetweenRolls seconds.";
			$sendto->reply($msg);
			return;
		}
		$options = preg_split("/(,\s+|\s+|,)/", $names);
		if ($amount > count($options)) {
			$msg = "Cannot pick more items than are on the list.";
			$sendto->reply($msg);
			return;
		}
		[$rollNumber, $result] = $this->roll($sender, $options, $amount);
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

		$sendto->reply($msg . " [" . $this->text->makeBlob("announce", $blob, "Announce result") . "]");
	}
	
	/**
	 * @HandlesCommand("roll")
	 * @Matches("/^roll (.+)$/i")
	 */
	public function rollNamesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$names = $args[1];
		$timeBetweenRolls = $this->settingManager->getInt('time_between_rolls');
		$row = $this->db->fetch(Roll::class, "SELECT * FROM roll WHERE `name` = ? AND `time` >= ? LIMIT 1", $sender, time() - $timeBetweenRolls);
		if ($row !== null) {
			$msg = "You can only roll once every $timeBetweenRolls seconds.";
			$sendto->reply($msg);
			return;
		}
		$options = preg_split("/(,\s+|\s+|,)/", $names);
		[$rollNumber, $result] = $this->roll($sender, $options);
		$msg = "The roll is <highlight>$result<end> out of the possible options ".
			$this->joinOptions($options, "highlight") . ". To verify do /tell <myname> verify $rollNumber";
		$blob = $this->text->makeChatcmd("Send to team chat", "/t $msg") . "\n".
			$this->text->makeChatcmd("Send to raid chat", "/g raid $msg");

		$sendto->reply($msg . " [" . $this->text->makeBlob("announce", $blob, "Announce result") . "]");
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
	
	/**
	 * @HandlesCommand("verify")
	 * @Matches("/^verify ([0-9]+)$/i")
	 */
	public function verifyCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		/** @var ?Roll */
		$row = $this->db->fetch(Roll::class, "SELECT * FROM roll WHERE `id` = ?", $id);
		if ($row === null) {
			$msg = "Roll number <highlight>$id<end> does not exist.";
		} else {
			$options = explode("|", $row->options);
			$result = explode("|", $row->result);
			$time = $this->util->unixtimeToReadable(time() - $row->time);
			$msg = $this->joinOptions($result, "highlight").
				" rolled by <highlight>$row->name<end> $time ago.\n".
				"Possible options were: ".
				$this->joinOptions($options, "highlight") . ".";
		}

		$sendto->reply($msg);
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
		$this->db->exec(
			"INSERT INTO roll (`time`, `name`, `options`, `result`) ".
			"VALUES (?, ?, ?, ?)",
			time(),
			$sender,
			implode("|", $options),
			implode("|", $result)
		);
		return [$this->db->lastInsertId(), implode("|", $result)];
	}
}
