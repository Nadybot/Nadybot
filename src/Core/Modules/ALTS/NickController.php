<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Illuminate\Database\QueryException;
use Nadybot\Core\Attributes\HandlesCommand;
use Nadybot\Core\DBSchema\Nickname;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	UserException,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "nick",
		accessLevel: "member",
		description: "Nickname handling",
	),
]
class NickController extends ModuleInstance {
	public const DB_TABLE = "nickname";

	/** How to display nicknames */
	#[NCA\Setting\Template(
		options: [
			"{nick}",
			"[{nick}]",
			"*{nick}",
			"<i>{nick}</i>",
			"<violet><i>{nick}</i><end>",
			"{main} ({nick})",
			"{main} (<i>{nick}</i>)",
			"{main} (<violet>{nick}<end>)",
		],
		exampleValues: [
			"nick" => "Nickname",
			"main" => "Mainname",
		],
		help: 'nick_format.txt',
	)]
	public string $nickFormat = "<i>{nick}</i>";

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private DB $db;

	/** @var array<string,string> */
	private array $nickNames = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->cacheNicknames();
	}

	#[NCA\Event(
		name: "timer(1h)",
		description: "Sync nickname-cache"
	)]
	public function reCacheNicknames(): void {
		$this->cacheNicknames();
	}

	/** Reload the nickname-cache from the database */
	public function cacheNicknames(): void {
		$this->nickNames = $this->db->table(self::DB_TABLE)
			->asObj(Nickname::class)
			->reduce(function (array $result, Nickname $data): array {
				$result[$data->main] = $data->nick;
				return $result;
			}, []);
	}

	/** Get the nickname of $char's main or null if none set */
	public function getNickname(string $char): ?string {
		$main = $this->altsController->getMainOf($char);
		return $this->nickNames[$main] ?? null;
	}

	/**
	 * Set $nick to be the nickname of $char's main
	 *
	 * @throws QueryException on error
	 */
	public function setNickname(string $char, string $nick): bool {
		if (strlen($nick) > 25) {
			throw new UserException("Your nickname is not allowed to be longer than 25 characters.");
		}
		if (strpos($nick, "<") !== false) {
			throw new UserException("Your Nickname must not contain any HTML-tags.");
		}
		$main = $this->altsController->getMainOf($char);
		if ($this->db->table(self::DB_TABLE)->whereIlike("nick", $nick)->exists()) {
			throw new UserException("The nickname '<highlight>{$nick}<end>' is already in use.");
		}
		$changeSuccess = $this->db->table(self::DB_TABLE)
			->where("main", $main)
			->updateOrInsert(
				["main" => $main],
				["main" => $main, "nick" => $nick]
			);
		if ($changeSuccess) {
			$this->nickNames[$main] = $nick;
		}
		return $changeSuccess;
	}

	/**
	 * Clear the nickname of $char's main
	 *
	 * @return bool true if removed, false if there was none
	 */
	public function clearNickname(string $char): bool {
		$main = $this->altsController->getMainOf($char);
		$nickDeleted = $this->db->table(self::DB_TABLE)
			->where("main", $main)
			->delete() > 0;
		unset($this->nickNames[$main]);
		return $nickDeleted;
	}

	#[NCA\Event(
		name: "alt(newmain)",
		description: "Move nickname to new main"
	)]
	public function moveNickname(AltEvent $event): void {
		$this->db->table(self::DB_TABLE)
			->where("main", $event->alt)
			->update(["main" => $event->main]);
		$this->cacheNicknames();
	}

	/** Show your current nickname */
	#[HandlesCommand("nick")]
	public function nickCommand(CmdContext $context): void {
		$nickname = $this->getNickname($context->char->name);
		if (!isset($nickname)) {
			$context->reply("You haven't set a nickname yet.");
			return;
		}
		$context->reply("Your nickname is <highlight>{$nickname}<end>.");
	}

	/**
	 * Set your nickname
	 * Make sure to start with a capital letter if you prefer this
	 */
	#[NCA\Help\Prologue(
		"Your nickname is shown instead of your main character's name on several\n".
		"places of the bot:\n".
		"whois, alts, online-list, logon/logoff-message, join/leave-message\n\n".
		"In order to be able to distinguish between a real character name and a nickname,\n".
		"you can force a style on nicknames in the ".
		"<a href='chatcmd:///tell <myname> settings change nick_format'>settings</a>.\n".
		"Keep in mind that nicknames can (and very likely will) collide with already\n".
		"existing names, but nicknames themselves are unique on a bot."
	)]
	#[HandlesCommand("nick")]
	public function setNickCommand(
		CmdContext $context,
		#[NCA\Str("set")]
		string $action,
		string $nick
	): void {
		if (!strlen($nick)) {
			$context->reply("Use '<highlight><symbol>nick erase<end>' to delete your nickname.");
			return;
		}
		$oldNickname = $this->getNickname($context->char->name);
		if (isset($oldNickname) && strcasecmp($oldNickname, $nick) === 0) {
			$context->reply("Nickname unchanged.");
			return;
		}
		if (!$this->setNickname($context->char->name, $nick)) {
			$context->reply("Unknown error changing your nickname to '<highlight>{$nick}<end>'.");
			return;
		}
		if (isset($oldNickname)) {
			$context->reply("Your nickname was changed to <highlight>{$nick}<end>.");
			return;
		}
		$context->reply("Your nickname was set to <highlight>{$nick}<end>.");
	}

	/** Clear your nickname */
	#[HandlesCommand("nick")]
	public function clearNickCommand(
		CmdContext $context,
		PRemove $action,
	): void {
		if (!$this->clearNickname($context->char->name)) {
			$context->reply("You don't have a nickname set.");
			return;
		}
		$context->reply("Nickname cleared.");
	}
}
