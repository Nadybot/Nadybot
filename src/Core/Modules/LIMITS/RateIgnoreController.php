<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS;

use Nadybot\Core\{
	CommandReply,
	DB,
	Nadybot,
	SQLException,
	Text,
};
use Nadybot\Core\DBSchema\RateIgnoreList;

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
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public $util;

	/** @Inject */
	public Nadybot $chatBot;

	/**
	 * @Setup
	 */
	public function setup() {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
	}

	/**
	 * @HandlesCommand("rateignore")
	 * @Matches("/^rateignore$/i")
	 */
	public function rateignoreCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$list = $this->all();
		if (count($list) === 0) {
			$sendto->reply("No entries in rate limit ignore list");
			return;
		}
		$blob = '';
		foreach ($list as $entry) {
			$remove = $this->text->makeChatcmd('Remove', "/tell <myname> rateignore remove $entry->name");
			$date = $this->util->date($entry->added_dt);
			$blob .= "<highlight>{$entry->name}<end> [added by {$entry->added_by}] {$date} {$remove}\n";
		}
		$msg = $this->text->makeBlob("Rate limit ignore list", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("rateignore")
	 * @Matches("/^rateignore add (.+)$/i")
	 */
	public function rateignoreAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply($this->add($args[1], $sender));
	}

	/**
	 * @HandlesCommand("rateignore")
	 * @Matches("/^rateignore (rem|remove|del|delete) (.+)$/i")
	 */
	public function rateignoreRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply($this->remove($args[2]));
	}

	/**
	 * Add someone to the RateIgnoreList
	 *
	 * @param string $user Person to add
	 * @param string $sender Name of the person that adds
	 * @return string Message of success or failure
	 * @throws SQLException
	 */
	public function add(string $user, string $sender): string {
		$user = ucfirst(strtolower($user));
		$sender = ucfirst(strtolower($sender));

		if ($user === '' || $sender === '') {
			return "User or sender is blank";
		}

		if ($this->check($user) === true) {
			return "Error! <highlight>$user<end> already added to the rate limit ignore list.";
		}
		$this->db->table("rateignorelist")
			->insert([
				"name" => $user,
				"added_by" => $sender,
				"added_dt" => time()
			]);
		return "<highlight>{$user}<end> has been added to the rate limit ignore list.";
	}

	/**
	 * Remove someone from the rate-limit ignore list
	 *
	 * @param string $user Who to remove
	 * @return string Message with success or failure
	 * @throws SQLException
	 */
	public function remove(string $user): string {
		$user = ucfirst(strtolower($user));

		if ($user === '') {
			return "User is blank";
		}

		if ($this->check($user) === false) {
			return "Error! <highlight>$user<end> is not on the rate limit ignore list.";
		}
		$this->db->table("rateignorelist")->where("name", $user)->delete();
		return "<highlight>{$user}<end> has been removed from the rate limit ignore list.";
	}

	public function check(string $user): bool {
		return $this->db->table("rateignorelist")
			->where("name", ucfirst(strtolower($user)))
			->exists();
	}

	/**
	 * Get all rateignorelist entries
	 *
	 * @return RateIgnoreList[]
	 * @throws SQLException
	 */
	public function all(): array {
		return $this->db->table("rateignorelist")
			->orderBy("name")
			->asObj(RateIgnoreList::class)
			->toArray();
	}
}
