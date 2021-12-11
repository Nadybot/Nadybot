<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ADMIN;

use Nadybot\Core\{
	AccessManager,
	AdminManager,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
	Modules\ALTS\AltsController,
};
use Nadybot\Core\DBSchema\Admin;
use Nadybot\Core\Modules\ALTS\AltEvent;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PRemove;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'adminlist',
 *		accessLevel   = 'all',
 *		description   = 'Shows the list of administrators and moderators',
 *		help          = 'adminlist.txt',
 *		alias         = 'admins',
 *		defaultStatus = '1'
 *	)
 *	@DefineCommand(
 *		command       = 'admin',
 *		accessLevel   = 'superadmin',
 *		description   = 'Add or remove an administrator',
 *		help          = 'admin.txt',
 *		defaultStatus = '1'
 *	)
 *	@DefineCommand(
 *		command       = 'mod',
 *		accessLevel   = 'admin',
 *		description   = 'Add or remove a moderator',
 *		help          = 'mod.txt',
 *		defaultStatus = '1'
 *	)
 */
class AdminController {

	/**
	 * Name of the module.
	 */
	public string $moduleName;

	/** @Inject */
	public AdminManager $adminManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AltsController $altsController;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		$this->adminManager->uploadAdmins();

		$this->commandAlias->register($this->moduleName, "admin add", "addadmin");
		$this->commandAlias->register($this->moduleName, "admin rem", "remadmin");
		$this->commandAlias->register($this->moduleName, "mod add", "addmod");
		$this->commandAlias->register($this->moduleName, "mod rem", "remmod");
	}

	/**
	 * @HandlesCommand("admin")
	 * @Mask $action add
	 */
	public function adminAddCommand(CmdContext $context, string $action, PCharacter $who): void {
		$intlevel = 4;
		$rank = 'an administrator';

		$this->add($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/**
	 * @HandlesCommand("mod")
	 * @Mask $action add
	 */
	public function modAddCommand(CmdContext $context, string $action, PCharacter $who): void {
		$intlevel = 3;
		$rank = 'a moderator';

		$this->add($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/**
	 * @HandlesCommand("admin")
	 */
	public function adminRemoveCommand(CmdContext $context, PRemove $rem, PCharacter $who): void {
		$intlevel = 4;
		$rank = 'an administrator';

		$this->remove($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/**
	 * @HandlesCommand("mod")
	 */
	public function modRemoveCommand(CmdContext $context, PRemove $rem, PCharacter $who): void {
		$intlevel = 3;
		$rank = 'a moderator';

		$this->remove($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/**
	 * @HandlesCommand("adminlist")
	 * @Mask $all all
	 */
	public function adminlistCommand(CmdContext $context, ?string $all): void {
		$showOfflineAlts = isset($all);
		$blob = "<header2>Administrators<end>\n";
		foreach ($this->adminManager->admins as $who => $data) {
			if ($this->adminManager->admins[$who]["level"] == 4) {
				if ($who != "") {
					$blob .= "<tab>$who";
					if ($this->accessManager->checkAccess($who, 'superadmin')) {
						$blob .= " (<highlight>Super-administrator<end>) ";
					}
					$blob .= $this->getOnlineStatus($who) . "\n" . $this->getAltAdminInfo($who, $showOfflineAlts);
				}
			}
		}

		$blob .= "<header2>Moderators<end>\n";
		foreach ($this->adminManager->admins as $who => $data) {
			if ($this->adminManager->admins[$who]["level"] == 3) {
				if ($who != "") {
					$blob .= "<tab>$who" . $this->getOnlineStatus($who) . "\n" . $this->getAltAdminInfo($who, $showOfflineAlts);
				}
			}
		}

		$link = $this->text->makeBlob('Bot administrators', $blob);
		$context->reply($link);
	}

	/**
	 * @Event("connect")
	 * @Description("Add administrators and moderators to the buddy list")
	 * @DefaultStatus("1")
	 */
	public function checkAdminsEvent(Event $eventObj): void {
		$this->db->table(AdminManager::DB_TABLE)->asObj(Admin::class)
			->each(function (Admin $row) {
				$this->buddylistManager->add($row->name, 'admin');
			});
	}

	/**
	 * Get the string of the online status
	 * @param string $who Playername
	 * @return string " (<green>online<end>)" and so on
	 */
	private function getOnlineStatus(string $who): string {
		if ($this->buddylistManager->isOnline($who) && isset($this->chatBot->chatlist[$who])) {
			return " (<green>Online and in chat<end>)";
		} elseif ($this->buddylistManager->isOnline($who)) {
			return " (<green>Online<end>)";
		} else {
			return " (<red>Offline<end>)";
		}
	}

	private function getAltAdminInfo(string $who, bool $showOfflineAlts): string {
		$blob = '';
		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main == $who) {
			foreach ($altInfo->getAllValidatedAlts() as $alt) {
				if ($showOfflineAlts || $this->buddylistManager->isOnline($alt)) {
					$blob .= "<tab><tab>$alt" . $this->getOnlineStatus($alt) . "\n";
				}
			}
		}
		return $blob;
	}

	public function add(string $who, string $sender, CommandReply $sendto, int $intlevel, string $rank): bool {
		if ($this->chatBot->get_uid($who) == null) {
			$sendto->reply("Character <highlight>$who<end> does not exist.");
			return false;
		}

		if ($this->adminManager->checkExisting($who, $intlevel)) {
			$sendto->reply("<highlight>$who<end> is already $rank.");
			return false;
		}

		if (!$this->checkAccessLevel($sender, $who)) {
			$sendto->reply("You must have a higher access level than <highlight>$who<end> in order to change his access level.");
			return false;
		}

		if (!$this->checkAltsInheritAdmin($who)) {
			$msg = "<red>WARNING<end>: $who is not a main.  This command did NOT affect $who's access level and no action was performed.";
			$sendto->reply($msg);
			return false;
		}

		$action = $this->adminManager->addToLists($who, $intlevel, $sender);

		$sendto->reply("<highlight>$who<end> has been $action to $rank.");
		$this->chatBot->sendTell("You have been $action to $rank by <highlight>$sender<end>.", $who);
		return true;
	}

	public function remove(string $who, string $sender, CommandReply $sendto, int $intlevel, string $rank): bool {
		if (!$this->adminManager->checkExisting($who, $intlevel)) {
			$sendto->reply("<highlight>$who<end> is not $rank.");
			return false;
		}

		if (!$this->checkAccessLevel($sender, $who)) {
			$sendto->reply("You must have a higher access level than <highlight>$who<end> in order to change his access level.");
			return false;
		}

		$this->adminManager->removeFromLists($who, $sender);

		if (!$this->checkAltsInheritAdmin($who)) {
			$msg = "<red>WARNING<end>: $who is not a main.  This command did NOT affect $who's access level.";
			$sendto->reply($msg);
		}

		$sendto->reply("<highlight>$who<end> has been removed as $rank.");
		$this->chatBot->sendTell("You have been removed as $rank by <highlight>$sender<end>.", $who);
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

	/**
	 * @Event("alt(newmain)")
	 * @Description("Move admin rank to new main")
	 */
	public function moveAdminrank(AltEvent $event): void {
		$oldRank = $this->adminManager->admins[$event->alt]??null;
		if (!isset($oldRank)) {
			return;
		}
		$this->adminManager->removeFromLists($event->alt, $event->main);
		$this->adminManager->addToLists($event->main, $oldRank["level"], $event->alt);
		$this->logger->notice("Moved {$event->alt}'s admin rank to {$event->main}.");
	}
}
