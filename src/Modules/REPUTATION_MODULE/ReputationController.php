<?php declare(strict_types=1);

namespace Nadybot\Modules\REPUTATION_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
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
 *		command     = 'reputation',
 *		accessLevel = 'guild',
 *		description = 'Allows people to see and add reputation of other players',
 *		help        = 'reputation.txt'
 *	)
 */
class ReputationController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"reputation_min_time",
			"How much time is required for leaving reputation for the same character",
			"edit",
			"time",
			"6h",
			"1h;6h;24h",
			'',
			"mod"
		);
		
		$this->db->loadSQLFile($this->moduleName, 'reputation');
	}

	/**
	 * @HandlesCommand("reputation")
	 * @Matches("/^reputation$/i")
	 */
	public function reputationListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT * FROM reputation ORDER BY dt DESC";

		/** @var Reputation[] */
		$data = $this->db->fetchAll(Reputation::class, $sql);
		$count = count($data);

		if ($count === 0) {
			$msg = "There are no characters on the reputation list.";
			$sendto->reply($msg);
			return;
		}

		$blob = '';
		/** @var array<string,stdClass> */
		$charReputation = [];
		foreach ($data as $row) {
			if (!array_key_exists($row->name, $charReputation)) {
				$charReputation[$row->name] = (object)['total' => 0, 'comments' => []];
			}
			$charReputation[$row->name]->comments[] = $row;
			$charReputation[$row->name]->total += ($row->reputation === '+1') ? 1 : -1;
		}
		$count = 0;
		foreach ($charReputation as $char => $charData) {
			$count++;
			$blob .= "<header2>$char<end>" . " (" . sprintf('%+d', $charData->total) . ")\n";
			$comments = array_slice($charData->comments, 0, 3);
			foreach ($comments as $row) {
				$color = ($row->reputation === '+1') ? 'green' : 'red';
				$blob .= "<tab><$color>{$row->comment}<end> ".
					"(<highlight>{$row->by}<end>, ".
					$this->util->date($row->dt) . ")\n";
			}
			if (count($charData->comments) > 3) {
				$details_link = $this->text->makeChatcmd('see all', "/tell <myname> reputation $row->name all");
				$blob .= "  $details_link\n";
			}
			$blob .= "\n<pagebreak>";
		}
		$msg = $this->text->makeBlob("Reputation List ($count)", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("reputation")
	 * @Matches("/^reputation ([a-z][a-z0-9-]+) (\+1|\-1) (.+)$/i")
	 */
	public function reputationAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$charid = $this->chatBot->get_uid($name);
		$rep = $args[2];
		$comment = $args[3];

		if (!$charid) {
			$sendto->reply("Character <highlight>$name<end> does not exist.");
			return;
		}

		if ($sender === $name) {
			$sendto->reply("You cannot give yourself reputation.");
			return;
		}

		$minTime = $this->settingManager->getInt('reputation_min_time');
		$time = time() - $minTime;

		$sql = "SELECT dt FROM reputation WHERE `by` = ? AND `name` = ? AND `dt` > ? ORDER BY dt DESC LIMIT 1";
		$row = $this->db->queryRow($sql, $sender, $name, $time);
		if ($row !== null) {
			$timeString = $this->util->unixtimeToReadable($row->dt - $time);
			$sendto->reply("You must wait $timeString before submitting more reputation for $name.");
			return;
		}

		$sql = "INSERT INTO reputation (`name`,`reputation`,`comment`,`by`,`dt`) ".
			"VALUES ( ?, ?, ?, ?, ?)";

		$this->db->exec($sql, $name, $rep, $comment, $sender, time());
		$sendto->reply("<highlight>$rep<end> reputation for <highlight>$name<end> added successfully.");
	}
	
	/**
	 * @HandlesCommand("reputation")
	 * @Matches("/^reputation ([a-z][a-z0-9-]+) (all)$/i")
	 * @Matches("/^reputation ([a-z][a-z0-9-]+)$/i")
	 */
	public function reputationViewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		
		$limit = 10;
		if (count($args) === 3) {
			$limit = 1000;
		}

		$sql = "SELECT ".
				"sum(CASE WHEN reputation = '+1' THEN 1 ELSE 0 END) AS positive_rep, ".
				"sum(CASE WHEN reputation = '-1' THEN 1 ELSE 0 END) AS negative_rep ".
			"FROM reputation ".
			"WHERE name = ?";

		$row = $this->db->queryRow($sql, $name);
		if ($row === null) {
			$msg = "<highlight>$name<end> has no reputation.";
			$sendto->reply($msg);
			return;
		}
		$numPositive = $row->positive_rep;
		$numNegative = $row->negative_rep;

		$blob = "Positive reputation:  <green>{$numPositive}<end>\n";
		$blob .= "Negative reputation: <red>{$numNegative}<end>\n\n";
		if ($limit !== 1000) {
			$blob .= "<header2>Last $limit comments about $name<end>\n";
		} else {
			$blob .= "<header>All comments about $name<end>\n";
		}

		$sql = "SELECT * FROM reputation WHERE name = ? ORDER BY `dt` DESC LIMIT ?";
		/** @var Reputation[] */
		$data = $this->db->fetchAll(Reputation::class, $sql, $name, $limit);
		foreach ($data as $row) {
			if ($row->reputation == '-1') {
				$blob .= "<red>";
			} else {
				$blob .= "<green>";
			}

			$time = $this->util->unixtimeToReadable(time() - $row->dt);
			$blob .= "<tab>$row->comment<end> (<highlight>$row->by<end>, {$time} ago)\n";
		}
		
		if ($limit !== 1000 && count($data) >= $limit) {
			$blob .= "\n" . $this->text->makeChatcmd("Show all comments", "/tell <myname> reputation $name all");
		}

		$msg = $this->text->makeBlob("Reputation for {$name} (+$numPositive -$numNegative)", $blob);

		$sendto->reply($msg);
	}
}
