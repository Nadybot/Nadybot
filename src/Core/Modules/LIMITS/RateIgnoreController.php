<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	DB,
	Nadybot,
	SQLException,
	Text,
	Util,
};
use Nadybot\Core\DBSchema\RateIgnoreList;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PRemove;

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "rateignore",
		accessLevel: "all",
		description: "Add players to the rate limit ignore list to bypass limits check",
		help: "rateignore.txt",
		defaultStatus: 1
	)
]
class RateIgnoreController {
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
	public Nadybot $chatBot;

	#[NCA\Setup]
	public function setup(): void {
	}

	#[NCA\HandlesCommand("rateignore")]
	public function rateignoreCommand(CmdContext $context): void {
		$list = $this->all();
		if (count($list) === 0) {
			$context->reply("No entries in rate limit ignore list");
			return;
		}
		$blob = '';
		foreach ($list as $entry) {
			$remove = $this->text->makeChatcmd('Remove', "/tell <myname> rateignore remove $entry->name");
			$date = $this->util->date($entry->added_dt);
			$blob .= "<highlight>{$entry->name}<end> [added by {$entry->added_by}] {$date} {$remove}\n";
		}
		$msg = $this->text->makeBlob("Rate limit ignore list", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("rateignore")]
	public function rateignoreAddCommand(CmdContext $context, #[NCA\Str("add")] string $action, PCharacter $who): void {
		$context->reply($this->add($who(), $context->char->name));
	}

	#[NCA\HandlesCommand("rateignore")]
	public function rateignoreRemoveCommand(CmdContext $context, PRemove $rem, PCharacter $who): void {
		$context->reply($this->remove($who()));
	}

	/**
	 * Add someone to the RateIgnoreList
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
			return "<highlight>$user<end> is already on the rate limit ignore list.";
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
			return "<highlight>$user<end> is not on the rate limit ignore list.";
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
