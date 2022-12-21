<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\HandlesCommand;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
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

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public DB $db;

	/** How to display nicknames */
	#[NCA\Setting\Template(
		options: [
			"{nick}",
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
	)]
	public string $nickFormat = "<i>{nick}</i>";

	/** Get the nickname of $char's main or null if none set */
	public function getNickname(string $char): ?string {
		$main = $this->altsController->getMainOf($char);
		$nickName = $this->db->table(self::DB_TABLE)
			->select("nick")
			->where("main", $main)
			->pluckStrings("nick")
			->first();
		return $nickName;
	}

	/** Set $nick to be the nickname of $char's main */
	public function setNickname(string $char, string $nick): bool {
		$main = $this->altsController->getMainOf($char);
		return $this->db->table(self::DB_TABLE)
			->where("main", $main)
			->updateOrInsert(
				["main" => $main],
				["main" => $main, "nick" => $nick]
			);
	}

	/**
	 * Clear the nickname of $char's main
	 *
	 * @return bool true if removed, false if there was none
	 */
	public function clearNickname(string $char): bool {
		$main = $this->altsController->getMainOf($char);
		return $this->db->table(self::DB_TABLE)
			->where("main", $main)
			->delete() > 0;
	}

	#[NCA\Event(
		name: "alt(newmain)",
		description: "Move nickname to new main"
	)]
	public function moveNickname(AltEvent $event): void {
		$this->db->table(self::DB_TABLE)
			->where("main", $event->alt)
			->update(["main" => $event->main]);
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

	/** Set your nickname */
	#[HandlesCommand("nick")]
	public function setNickCommand(
		CmdContext $context,
		#[NCA\Str("set")] string $action,
		string $nick
	): void {
		if (!strlen($nick)) {
			$context->reply("Use '<highlight><symbol>nick erase<end>' to delete your nickname.");
			return;
		}
		if (strlen($nick) > 25) {
			$context->reply("Your nickname is not allowed to be longer than 25 characters.");
			return;
		}
		$oldNickname = $this->getNickname($context->char->name);
		if (!$this->setNickname($context->char->name, $nick)) {
			$context->reply("There was an unknown error seetting your nickname.");
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
		$context->reply("Nickname clearned.");
	}
}
