<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ADMIN;

use Amp\Promise;
use Generator;
use Nadybot\Core\{
	AccessManager,
	AdminManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandReply,
	DB,
	DBSchema\Admin,
	DBSchema\LastOnline,
	Event,
	LoggerWrapper,
	ModuleInstance,
	Modules\ALTS\AltEvent,
	Modules\ALTS\AltsController,
	Modules\ALTS\NickController,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	SettingManager,
	Text,
	Util,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "adminlist",
		accessLevel: "all",
		description: "Shows the list of administrators and moderators",
		defaultStatus: 1,
		alias: "admins"
	),
	NCA\DefineCommand(
		command: "admin",
		accessLevel: "superadmin",
		description: "Add or remove an administrator",
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: "mod",
		accessLevel: "admin",
		description: "Add or remove a moderator",
		defaultStatus: 1
	)
]
class AdminController extends ModuleInstance {
	#[NCA\Inject]
	public AdminManager $adminManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public NickController $nickController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->adminManager->uploadAdmins();

		$this->commandAlias->register($this->moduleName, "admin add", "addadmin");
		$this->commandAlias->register($this->moduleName, "admin rem", "remadmin");
		$this->commandAlias->register($this->moduleName, "mod add", "addmod");
		$this->commandAlias->register($this->moduleName, "mod rem", "remmod");
	}

	/** Make &lt;who&gt; an administrator */
	#[NCA\HandlesCommand("admin")]
	#[NCA\Help\Group("ranks")]
	public function adminAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PCharacter $who
	): void {
		$intlevel = 4;
		$rankName = $this->accessManager->getDisplayName("admin");
		$rank = $this->addArticle($rankName);

		$this->add($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/** Make &lt;who&gt; a moderator */
	#[NCA\HandlesCommand("mod")]
	#[NCA\Help\Group("ranks")]
	public function modAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PCharacter $who
	): void {
		$intlevel = 3;
		$rankName = $this->accessManager->getDisplayName("mod");
		$rank = $this->addArticle($rankName);

		$this->add($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/** Demote &lt;who&gt; from administrator */
	#[NCA\HandlesCommand("admin")]
	#[NCA\Help\Group("ranks")]
	public function adminRemoveCommand(CmdContext $context, PRemove $rem, PCharacter $who): void {
		$intlevel = 4;
		$rankName = $this->accessManager->getDisplayName("admin");
		$rank = $this->addArticle($rankName);

		$this->remove($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/** Demote &lt;who&gt; from moderator */
	#[NCA\HandlesCommand("mod")]
	#[NCA\Help\Group("ranks")]
	public function modRemoveCommand(CmdContext $context, PRemove $rem, PCharacter $who): void {
		$intlevel = 3;
		$rankName = $this->accessManager->getDisplayName("mod");
		$rank = $this->addArticle($rankName);

		$this->remove($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/**
	 * See the list of moderators and administrators.
	 * Add 'all' to include offline alts
	 */
	#[NCA\HandlesCommand("adminlist")]
	#[NCA\Help\Group("ranks")]
	public function adminlistCommand(CmdContext $context, #[NCA\Str("all")] ?string $all): void {
		$blobs = $this->getLeaderList(isset($all));

		$link = $this->text->makeBlob('Bot administrators', join("\n", $blobs));
		$context->reply($link);
	}

	/** @return string[] */
	public function getLeaderList(bool $showOfflineAlts): array {
		$admins = [];
		$mods = [];
		$blobs = [];
		foreach ($this->adminManager->admins as $who => $data) {
			if ($who === '') {
				continue;
			}
			$nick = $this->nickController->getNickname($who);
			if (isset($nick)) {
				$line = "<tab>{$nick} ({$who})";
			} else {
				$line = "<tab>{$who}";
			}
			if ($this->accessManager->checkAccess($who, 'superadmin')) {
				$line .= " (<highlight>".
					ucfirst($this->accessManager->getDisplayName("superadmin")).
					"<end>)";
			}
			$line .= $this->getOnlineStatus($who, true) . "\n".
				$this->getAltAdminInfo($who, $showOfflineAlts);
			if ($data["level"] === 4) {
				$admins []= $line;
			} elseif ($data["level"] === 3) {
				$mods []= $line;
			}
		}
		if (count($admins)) {
			$blobs []= "<header2>".
				ucfirst($this->accessManager->getDisplayName("admin")).
				"s<end>\n".
				join("", $admins);
		}
		if (count($mods)) {
			$blobs []= "<header2>".
				ucfirst($this->accessManager->getDisplayName("mod")).
				"s<end>\n".
				join("", $mods);
		}
		return $blobs;
	}

	#[NCA\Event(
		name: "connect",
		description: "Add administrators and moderators to the buddy list",
		defaultStatus: 1
	)]
	public function checkAdminsEvent(Event $eventObj): Generator {
		yield $this->db->table(AdminManager::DB_TABLE)->asObj(Admin::class)
			->map(function (Admin $row): Promise {
				return $this->buddylistManager->addAsync($row->name, 'admin');
			})->toArray();
	}

	public function add(string $who, string $sender, CommandReply $sendto, int $intlevel, string $rank): bool {
		if ($this->chatBot->get_uid($who) == null) {
			$sendto->reply("Character <highlight>{$who}<end> does not exist.");
			return false;
		}

		if ($this->adminManager->checkExisting($who, $intlevel)) {
			$sendto->reply("<highlight>{$who}<end> is already {$rank}.");
			return false;
		}

		if (!$this->checkAccessLevel($sender, $who)) {
			$sendto->reply("You must have a higher access level than <highlight>{$who}<end> in order to change his access level.");
			return false;
		}

		if (!$this->checkAltsInheritAdmin($who)) {
			$msg = "<red>WARNING<end>: {$who} is not a main.  This command did NOT affect {$who}'s access level and no action was performed.";
			$sendto->reply($msg);
			return false;
		}

		$action = $this->adminManager->addToLists($who, $intlevel, $sender);

		$sendto->reply("<highlight>{$who}<end> has been {$action} to {$rank}.");
		$this->chatBot->sendTell("You have been {$action} to {$rank} by <highlight>{$sender}<end>.", $who);
		return true;
	}

	public function remove(string $who, string $sender, CommandReply $sendto, int $intlevel, string $rank): bool {
		if (!$this->adminManager->checkExisting($who, $intlevel)) {
			$sendto->reply("<highlight>{$who}<end> is not {$rank}.");
			return false;
		}

		if (!$this->checkAccessLevel($sender, $who)) {
			$sendto->reply("You must have a higher access level than <highlight>{$who}<end> in order to change his access level.");
			return false;
		}

		$this->adminManager->removeFromLists($who, $sender);

		if (!$this->checkAltsInheritAdmin($who)) {
			$msg = "<red>WARNING<end>: {$who} is not a main.  This command did NOT affect {$who}'s access level.";
			$sendto->reply($msg);
		}

		$sendto->reply("<highlight>{$who}<end> has been removed as {$rank}.");
		$this->chatBot->sendTell("You have been removed as {$rank} by <highlight>{$sender}<end>.", $who);
		return true;
	}

	public function checkAltsInheritAdmin(string $who): bool {
		$ai = $this->altsController->getAltInfo($who);
		return $ai->main == $who;
	}

	public function checkAccessLevel(string $actor, string $actee): bool {
		$senderAccessLevel = $this->accessManager->getAccessLevelForCharacter($actor);
		$whoAccessLevel = $this->accessManager->getSingleAccessLevel($actee);
		return $this->accessManager->compareAccessLevels($whoAccessLevel, $senderAccessLevel) < 0;
	}

	#[NCA\Event(
		name: "alt(newmain)",
		description: "Move admin rank to new main"
	)]
	public function moveAdminrank(AltEvent $event): void {
		$oldRank = $this->adminManager->admins[$event->alt]??null;
		if (!isset($oldRank)) {
			return;
		}
		$this->adminManager->removeFromLists($event->alt, $event->main);
		$this->adminManager->addToLists($event->main, $oldRank["level"], $event->alt);
		$this->logger->notice("Moved {$event->alt}'s admin rank to {$event->main}.");
	}

	private function addArticle(string $rank): string {
		return in_array(substr($rank, 0, 1), ["a", "e", "i", "o", "u"])
			? "an {$rank}"
			: "a {$rank}";
	}

	/**
	 * Get the string of the online status
	 *
	 * @param string $who Playername
	 *
	 * @return string " (<on>online<end>)" and so on
	 */
	private function getOnlineStatus(string $who, bool $showLastSeen=false): string {
		if ($this->buddylistManager->isOnline($who) && isset($this->chatBot->chatlist[$who])) {
			return " (<on>Online and in chat<end>)";
		} elseif ($this->buddylistManager->isOnline($who)) {
			return " (<on>Online<end>)";
		}
		if (!$showLastSeen) {
			return " (<off>Offline<end>)";
		}
		$main = $this->altsController->getMainOf($who);

		/** @var ?LastOnline */
		$lastSeen = $this->db->table("last_online")
			->whereIn("name", $this->altsController->getAltsOf($main))
			->orderByDesc("dt")
			->limit(1)
			->asObj(LastOnline::class)
			->first();
		if (!isset($lastSeen)) {
			return " (<off>Offline<end>)";
		}
		return " (<off>Offline<end>, last seen ".
			$this->util->date($lastSeen->dt, false).
			" on {$lastSeen->name})";
	}

	private function getAltAdminInfo(string $who, bool $showOfflineAlts): string {
		$blob = '';
		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main == $who) {
			foreach ($altInfo->getAllValidatedAlts() as $alt) {
				if ($showOfflineAlts || $this->buddylistManager->isOnline($alt)) {
					$blob .= "<tab><tab>{$alt}" . $this->getOnlineStatus($alt) . "\n";
				}
			}
		}
		return $blob;
	}
}
