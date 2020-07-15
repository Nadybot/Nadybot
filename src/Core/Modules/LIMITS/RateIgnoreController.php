<?php

namespace Budabot\Core\Modules\LIMITS;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command       = 'rateignore',
 *		accessLevel   = 'all',
 *		description   = 'Add players to the rate limit ignore list to bypass limits check',
 *		help          = 'rateignore.txt',
 *		defaultStatus = '1'
 *	)
 */
class RateIgnoreController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;
	
	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, 'rateignorelist');
		if (!$this->db->tableExists("whitelist")) {
			return;
		}
		$this->db->exec(
			"INSERT INTO rateignorelist (name, added_by, added_dt) ".
				"SELECT w.name, w.added_by, w.added_dt FROM whitelist w"
		);
		$this->db->exec("DROP TABLE IF EXISTS whitelist");
	}
	
	/**
	 * @HandlesCommand("rateignore")
	 * @Matches("/^rateignore$/i")
	 */
	public function rateignoreCommand($message, $channel, $sender, $sendto, $args) {
		$list = $this->all();
		if (count($list) == 0) {
			$sendto->reply("No entries in rate limit ignore list");
		} else {
			$blob = '';
			foreach ($list as $entry) {
				$remove = $this->text->makeChatcmd('Remove', "/tell <myname> rateignore remove $entry->name");
				$date = $this->util->date($entry->added_dt);
				$blob .= "<highlight>{$entry->name}<end> [added by {$entry->added_by}] {$date} {$remove}\n";
			}
			$msg = $this->text->makeBlob("Rate limit ignore list", $blob);
			$sendto->reply($msg);
		}
	}
	
	/**
	 * @HandlesCommand("rateignore")
	 * @Matches("/^rateignore add (.+)$/i")
	 */
	public function rateignoreAddCommand($message, $channel, $sender, $sendto, $args) {
		$sendto->reply($this->add($args[1], $sender));
	}
	
	/**
	 * @HandlesCommand("rateignore")
	 * @Matches("/^rateignore (rem|remove|del|delete) (.+)$/i")
	 */
	public function rateignoreRemoveCommand($message, $channel, $sender, $sendto, $args) {
		$sendto->reply($this->remove($args[2]));
	}

	public function add($user, $sender) {
		$user = ucfirst(strtolower($user));
		$sender = ucfirst(strtolower($sender));

		if ($user == '' || $sender == '') {
			return "User or sender is blank";
		}

		$data = $this->db->query("SELECT * FROM rateignorelist WHERE name = ?", $user);
		if (count($data) != 0) {
			return "Error! <highlight>$user<end> already added to the rate limit ignore list.";
		} else {
			$this->db->exec("INSERT INTO rateignorelist (name, added_by, added_dt) VALUES (?, ?, ?)", $user, $sender, time());
			return "<highlight>$user<end> has been added to the rate limit ignore list.";
		}
	}

	public function remove($user) {
		$user = ucfirst(strtolower($user));

		if ($user == '') {
			return "User is blank";
		}

		$data = $this->db->query("SELECT * FROM rateignorelist WHERE name = ?", $user);
		if (count($data) == 0) {
			return "Error! <highlight>$user<end> is not on the rate limit ignore list.";
		} else {
			$this->db->exec("DELETE FROM rateignorelist WHERE name = ?", $user);
			return "<highlight>$user<end> has been removed from the rate limit ignore list.";
		}
	}

	public function check($user) {
		$user = ucfirst(strtolower($user));

		$row = $this->db->queryRow("SELECT * FROM rateignorelist WHERE name = ? LIMIT 1", $user);
		if ($row === null) {
			return false;
		} else {
			return true;
		}
	}

	public function all() {
		return $this->db->query("SELECT * FROM rateignorelist ORDER BY name ASC");
	}
}
