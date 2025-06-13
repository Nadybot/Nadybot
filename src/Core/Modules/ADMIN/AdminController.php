<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ADMIN;

use Nadybot\Core\{
	AccessManager,
	AdminManager,
	Attributes as NCA,
	Attributes\Parameter\Remove,
	Attributes\Parameter\Str,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	DB,
	DBSchema\Admin,
	DBSchema\LastOnline,
	Events\ConnectEvent,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\ALTS\NickController,
	Nadybot,
	ParamClass\PCharacter,
	Text,
	Types\AccessLevel,
	Types\CommandReply,
	Types\Status,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltNewMainEvent;
use Psr\Log\LoggerInterface;

/** This is the main controller with commands to modify player's admin/mod ranks */
#[
	NCA\Instance,
	NCA\HasTests,
	NCA\DefineCommand(
		command: 'adminlist',
		accessLevel: AccessLevel::All,
		description: 'Shows the list of administrators and moderators',
		defaultStatus: Status::Enabled,
		alias: 'admins'
	),
	NCA\DefineCommand(
		command: 'admin',
		accessLevel: AccessLevel::Superadmin,
		description: 'Add or remove an administrator',
		defaultStatus: Status::Enabled,
	),
	NCA\DefineCommand(
		command: 'mod',
		accessLevel: AccessLevel::Admin,
		description: 'Add or remove a moderator',
		defaultStatus: Status::Enabled,
	)
]
class AdminController extends ModuleInstance {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private AdminManager $adminManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private NickController $nickController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Setup]
	public function setup(): void {
		$this->adminManager->uploadAdmins();

		$this->commandAlias->register($this->moduleName, 'admin add', 'addadmin');
		$this->commandAlias->register($this->moduleName, 'admin rem', 'remadmin');
		$this->commandAlias->register($this->moduleName, 'mod add', 'addmod');
		$this->commandAlias->register($this->moduleName, 'mod rem', 'remmod');
	}

	/** Make &lt;who&gt; an administrator */
	#[NCA\HandlesCommand('admin')]
	#[NCA\Help\Group('ranks')]
	public function adminAddCommand(
		CmdContext $context,
		#[Str('add')] string $action,
		PCharacter $who
	): void {
		$intlevel = 4;
		$rankName = AccessLevel::Admin->displayName();
		$rank = Text::addArticle($rankName);

		$this->add($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/** Make &lt;who&gt; a moderator */
	#[NCA\HandlesCommand('mod')]
	#[NCA\Help\Group('ranks')]
	public function modAddCommand(
		CmdContext $context,
		#[Str('add')] string $action,
		PCharacter $who
	): void {
		$intlevel = 3;
		$rankName = AccessLevel::Mod->displayName();
		$rank = Text::addArticle($rankName);

		$this->add($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/** Demote &lt;who&gt; from administrator */
	#[NCA\HandlesCommand('admin')]
	#[NCA\Help\Group('ranks')]
	public function adminRemoveCommand(CmdContext $context, #[Remove] string $rem, PCharacter $who): void {
		$intlevel = 4;
		$rankName = AccessLevel::Admin->displayName();
		$rank = Text::addArticle($rankName);

		$this->remove($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/** Demote &lt;who&gt; from moderator */
	#[NCA\HandlesCommand('mod')]
	#[NCA\Help\Group('ranks')]
	public function modRemoveCommand(CmdContext $context, #[Remove] string $rem, PCharacter $who): void {
		$intlevel = 3;
		$rankName = AccessLevel::Mod->displayName();
		$rank = Text::addArticle($rankName);

		$this->remove($who(), $context->char->name, $context, $intlevel, $rank);
	}

	/**
	 * See the list of moderators and administrators.
	 * Add 'all' to include offline alts
	 */
	#[NCA\HandlesCommand('adminlist')]
	#[NCA\Help\Group('ranks')]
	public function adminlistCommand(CmdContext $context, #[Str('all')] ?string $all): void {
		$blobs = $this->getLeaderList(isset($all));

		$link = Text::makeBlob('Bot administrators', implode("\n", $blobs));
		$context->reply($link);
	}

	/**
	 * Get a list of rendered blobs for
	 * * Superadmins
	 * * Admins
	 * * Mods
	 *
	 * @param bool $showOfflineAlts Show a complete list of all of each admin's alts,
	 *                              even if they are offline.
	 * @param bool $showSuperAdmins Include Superadmins in the lists.
	 *                              Technically, they are admins, but very
	 *                              often, they don't act as such and only
	 *                              focus on technical administration.
	 *
	 * @return list<string> A list of rendered blobs. If one of the admin
	 *                      access levels is empty, then it
	 *                      will be omitted from the list.
	 */
	public function getLeaderList(bool $showOfflineAlts, bool $showSuperAdmins=true): array {
		$superadmins = [];
		$admins = [];
		$mods = [];
		$blobs = [];
		foreach ($this->adminManager->getAdmins() as $who => $adminRank) {
			if ($who === '') {
				continue;
			}
			$isSuperAdmin = $this->accessManager->checkAccess($who, AccessLevel::Superadmin);
			if ($isSuperAdmin && !$showSuperAdmins) {
				continue;
			}
			$nick = $this->nickController->getNickname($who);
			if (isset($nick)) {
				$line = "<tab>{$nick} ({$who})";
			} else {
				$line = "<tab>{$who}";
			}
			$line .= $this->getOnlineStatus($who, true) . "\n".
				$this->getAltAdminInfo($who, $showOfflineAlts);
			if ($isSuperAdmin) {
				$superadmins []= $line;
			} elseif ($adminRank === 4) {
				$admins []= $line;
			} elseif ($adminRank === 3) {
				$mods []= $line;
			}
		}
		if (count($superadmins)) {
			$blobs []= '<header2>'.
				ucfirst(AccessLevel::Superadmin->displayName()).
				"s<end>\n".
				implode('', $superadmins);
		}
		if (count($admins)) {
			$blobs []= '<header2>'.
				ucfirst(AccessLevel::Admin->displayName()).
				"s<end>\n".
				implode('', $admins);
		}
		if (count($mods)) {
			$blobs []= '<header2>'.
				ucfirst(AccessLevel::Mod->displayName()).
				"s<end>\n".
				implode('', $mods);
		}
		return $blobs;
	}

	/** Add administrators and moderators to the buddy list */
	#[NCA\HandlesEvent(defaultStatus: Status::Enabled)]
	public function checkAdminsEvent(ConnectEvent $eventObj): void {
		$this->db->table(Admin::getTable())->asObj(Admin::class)
			->each(function (Admin $row): void {
				$this->buddylistManager->addName($row->name, 'admin');
			});
	}

	/** Check if the given character is the main of the player */
	public function checkAltsInheritAdmin(string $who): bool {
		$ai = $this->altsController->getAltInfo($who);
		return $ai->main === $who;
	}

	/** Check if `$actor`'s access level is higher than `$actee`'s */
	public function checkAccessLevel(string $actor, string $actee): bool {
		$actorAccessLevel = $this->accessManager->getAccessLevelForCharacter($actor);
		$acteeAccessLevel = $this->accessManager->getSingleAccessLevel($actee);
		return $acteeAccessLevel->lowerThan($actorAccessLevel);
	}

	/** Move admin rank to new main */
	#[NCA\HandlesEvent]
	public function moveAdminrank(AltNewMainEvent $event): void {
		$oldRank = $this->adminManager->getAdminLevel($event->alt);
		if (!isset($oldRank)) {
			return;
		}
		$this->adminManager->removeFromLists($event->alt, $event->main);
		$this->adminManager->addToLists($event->main, $oldRank, $event->alt);
		$this->logger->notice("Moved {alt}'s admin rank to {main}.", [
			'alt' => $event->alt,
			'main' => $event->main,
		]);
	}

	/**
	 * Remove the admin rank for `$who`
	 *
	 * @param string       $who      Whose admin level to chance
	 * @param string       $sender   Who is changing `$who`se admin level
	 * @param CommandReply $sendto   Where to send the result of the operation to
	 * @param int          $intlevel The admin level that `$who` is expected to have:
	 *                               * `3`: admin
	 *                               * `4`: mod
	 * @param string       $rank     The name of the rank of `$intlevel`:
	 *                               * `'mod'`
	 *                               * `'admin'`
	 *
	 * @return bool Success or not
	 */
	private function remove(string $who, string $sender, CommandReply $sendto, int $intlevel, string $rank): bool {
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

	/**
	 * Promote or demote someone to mod/admin
	 *
	 * @param string       $who      Whose admin level to change
	 * @param string       $sender   Who is chancing `$who`se admin level
	 * @param CommandReply $sendto   Where to send the result of the operation to
	 * @param int          $intlevel The new admin level to set
	 *                               * `3`: admin
	 *                               * `4`: mod
	 * @param string       $rank     The name of the rank of `$intlevel`:
	 *                               * `'mod'`
	 *                               * `'admin'`
	 *
	 * @return bool Success or not
	 */
	private function add(string $who, string $sender, CommandReply $sendto, int $intlevel, string $rank): bool {
		if ($this->chatBot->getUid($who) === null) {
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

		$sendto->reply("<highlight>{$who}<end> has been {$action->value} to {$rank}.");
		$this->chatBot->sendTell("You have been {$action->value} to {$rank} by <highlight>{$sender}<end>.", $who);
		return true;
	}

	/**
	 * Get the string of the online status
	 *
	 * @param string $who          name of the character
	 * @param bool   $showLastSeen If `$who` is offline, add when they were last seen
	 *
	 * @return string `' (<on>online<end>)'` and so on
	 */
	private function getOnlineStatus(string $who, bool $showLastSeen=false): string {
		if ($this->buddylistManager->isOnline($who) === true && $this->chatBot->inChatlist($who)) {
			return ' (<on>Online and in chat<end>)';
		} elseif ($this->buddylistManager->isOnline($who)) {
			return ' (<on>Online<end>)';
		}
		if (!$showLastSeen) {
			return ' (<off>Offline<end>)';
		}
		$main = $this->altsController->getMainOf($who);

		$lastSeen = $this->db->table(LastOnline::getTable())
			->whereIn('name', $this->altsController->getAltsOf($main))
			->orderByDesc('dt')
			->firstObj(LastOnline::class);
		if (!isset($lastSeen)) {
			return ' (<off>Offline<end>)';
		}
		return ' (<off>Offline<end>, last seen '.
			Util::date($lastSeen->dt, false).
			" on {$lastSeen->name})";
	}

	/**
	 * Get all lines as a blob for a character in the admin list display,
	 * including all their currently online alts.
	 *
	 * @param string $who             Name of the admin character
	 * @param bool   $showOfflineAlts Include the offline alts as well
	 *
	 * @return string The rendered blob
	 */
	private function getAltAdminInfo(string $who, bool $showOfflineAlts): string {
		$blob = '';
		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main === $who) {
			foreach ($altInfo->getAllValidatedAlts() as $alt) {
				if ($showOfflineAlts || $this->buddylistManager->isOnline($alt) === true) {
					$blob .= "<tab><tab>{$alt}" . $this->getOnlineStatus($alt) . "\n";
				}
			}
		}
		return $blob;
	}
}
