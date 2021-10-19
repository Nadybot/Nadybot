<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use Nadybot\Core\{
	AccessManager,
	BuddylistManager,
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
	Text,
	Modules\PREFERENCES\Preferences,
};
use Nadybot\Core\Modules\BAN\BanController;

/**
 * This class contains all functions necessary for mass messaging
 *
 * @Instance
 * @package Nadybot\Modules\MASSMSG_MODULE
 *
 * @DefineCommand(
 *     command       = 'massmsg',
 *     accessLevel   = 'mod',
 *     description   = 'Send messages to all bot members online',
 *     help          = 'massmsg.txt',
 *     alias         = 'massmessage'
 * )
 *
 * @DefineCommand(
 *     command       = 'massmsgs',
 *     accessLevel   = 'member',
 *     description   = 'Control if you want to receive mass messages',
 *     help          = 'massmsg.txt'
 * )
 * @DefineCommand(
 *     command       = 'massinvites',
 *     accessLevel   = 'member',
 *     description   = 'Control if you want to receive mass invites',
 *     help          = 'massmsg.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'massinv',
 *     accessLevel   = 'mod',
 *     description   = 'Send invites with a message to all bot members online',
 *     help          = 'massmsg.txt',
 *     alias         = 'massinvite'
 * )
 */
class MassMsgController {
	public const BLOCKED = 'blocked';
	public const IN_CHAT = 'in chat';
	public const IN_ORG  = 'in org';
	public const SENT    = 'sent';

	public const PREF_MSGS = 'massmsgs';
	public const PREF_INVITES = 'massinvites';

	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public BanController $banController;

	/** @Inject */
	public Preferences $preferences;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"massmsg_color",
			"Color for mass messages/invites",
			"edit",
			"color",
			"<font color='#FF9999'>",
		);
	}

	protected function getMassMsgOptInOutBlob(): string {
		$msgOnLink      = $this->text->makeChatcmd("On", "/tell <myname> massmsgs on");
		$msgOffLink     = $this->text->makeChatcmd("Off", "/tell <myname> massmsgs off");
		$invitesOnLink  = $this->text->makeChatcmd("On", "/tell <myname> massinvites on");
		$invitesOffLink = $this->text->makeChatcmd("Off", "/tell <myname> massinvites off");
		$blob = "<header2>Not interested?<end>\n".
			"<tab>Change your preferences:\n\n".
			"<tab>[{$msgOnLink}] [{$msgOffLink}]  Mass messages\n".
			"<tab>[{$invitesOnLink}] [{$invitesOffLink}]  Mass invites\n";
		$prefLink = $this->text->makeBlob("Preferences", $blob, "Change your mass message preferences");

		return "[{$prefLink}]";
	}

	/**
	 * @HandlesCommand("massmsg")
	 * @Matches("/^massmsg (.+)$/i")
	 */
	public function massMsgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$message = "<highlight>Message from {$sender}<end>: ".
			$this->settingManager->getString('massmsg_color') . $args[1] . "<end>";
		$this->chatBot->sendPrivate($message, true);
		$this->chatBot->sendGuild($message, true);
		$message .= " :: " . $this->getMassMsgOptInOutBlob();
		$result = $this->massCallback([
			static::PREF_MSGS => function(string $name) use ($message) {
				$this->chatBot->sendMassTell($message, $name);
			}
		]);
		$msg = $this->getMassResultPopup($result);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("massinv")
	 * @Matches("/^massinv (.+)$/i")
	 */
	public function massInvCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$message = "<highlight>Invite from {$sender}<end>: ".
			$this->settingManager->getString('massmsg_color') . $args[1] . "<end>";
		$this->chatBot->sendPrivate($message, true);
		$this->chatBot->sendGuild($message, true);
		$message .= " :: " . $this->getMassMsgOptInOutBlob();
		$result = $this->massCallback([
			static::PREF_MSGS => function(string $name) use ($message) {
				$this->chatBot->sendMassTell($message, $name);
			},
			static::PREF_INVITES => [$this->chatBot, "privategroup_invite"],
		]);
		$msg = $this->getMassResultPopup($result);
		$sendto->reply($msg);
	}

	/**
	 * Turn the result of a massCallback() into a nice popup
	 */
	protected function getMassResultPopup(array $result): array {
		ksort($result);
		$blob = "<header2>Result of your mass message<end>\n";
		$numSent = 0;
		$numInChat = 0;
		$numInOrg = 0;
		$numBlocked = 0;
		foreach ($result as $player => $action) {
			$blob .= "<tab>$player: ";
			if ($action === static::BLOCKED) {
				$blob .= "<red>blocked by preferences<end>";
				$numBlocked++;
			} elseif ($action === static::IN_CHAT) {
				$blob .= "<green>read in chat<end>";
				$numInChat++;
			} elseif ($action === static::IN_ORG) {
				$blob .= "<green>read in org<end>";
				$numInOrg++;
			} elseif ($action === static::SENT) {
				$blob .= "<green>message sent<end>";
				$numSent++;
			}
			$blob .= "\n";
		}
		$person = function(int $num): string {
			return ($num === 1) ? "person" : "people";
		};
		$isAre = function(int $num): string {
			return ($num === 1) ? "is" : "are";
		};
		$msg = "Your message was sent to <highlight>{$numSent}<end> ".
			$person($numSent).
			" and read by <highlight>{$numInChat}<end> ".
			$person($numInChat) . " in the private channel";
		if ($numInOrg > 0) {
			$msg .= " and by <highlight>{$numInOrg}<end> ".
			$person($numInOrg) . " in the org chat";
		}
		$msg .= ".";
		if ($numBlocked > 0) {
			$msg .= " {$numBlocked} " . $person($numBlocked) . " " . $isAre($numBlocked).
			" blocking mass messages";
		}
		if (count($result) === 0) {
			return (array)$msg;
		}
		$parts = (array)$this->text->makeBlob("Messaging details", $blob);
		foreach ($parts as &$part) {
			$part = "$msg :: $part";
		}
		return $parts;
	}

	/**
	 * Run a callback for all users that are members, online but not in
	 * our private channel.
	 * @return array<string,string> array(name => status)
	 */
	protected function massCallback(array $callback): array {
		$online = $this->buddylistManager->getOnline();
		$result = [];
		foreach ($online as $name) {
			$uid = $this->chatBot->get_uid($name);
			if ($uid === false || $this->banController->isBanned($uid)) {
				continue;
			}
			if ($name === $this->chatBot->vars["name"]
				|| !$this->accessManager->checkAccess($name, "member")) {
				continue;
			}
			if (isset($this->chatBot->chatlist[$name])) {
				$result[$name] = static::IN_CHAT;
				continue;
			}
			if (isset($this->chatBot->guildmembers[$name])) {
				$result[$name] = static::IN_ORG;
				continue;
			}
			foreach ($callback as $pref => $closure) {
				if ($this->preferences->get($name, $pref) === 'no') {
					$result[$name] = static::BLOCKED;
					continue;
				}
				$closure($name);
				$result[$name] ??= static::SENT;
			}
		}
		return $result;
	}

	/** Show a character their current mass message and -invite preferences */
	protected function showMassPreferences(string $character, CommandReply $sendto): void {
		$msgs = $this->preferences->get($character, static::PREF_MSGS);
		$invs = $this->preferences->get($character, static::PREF_INVITES);
		$msgOnLink      = $this->text->makeChatcmd("On", "/tell <myname> massmsgs on");
		$msgOffLink     = $this->text->makeChatcmd("Off", "/tell <myname> massmsgs off");
		$invitesOnLink  = $this->text->makeChatcmd("On", "/tell <myname> massinvites on");
		$invitesOffLink = $this->text->makeChatcmd("Off", "/tell <myname> massinvites off");
		if ($msgs === "no") {
			$msgOffLink = "<red>Off<end>";
		} else {
			$msgOnLink = "<green>On<end>";
		}
		if ($invs === "no") {
			$invitesOffLink = "<red>Off<end>";
		} else {
			$invitesOnLink = "<green>On<end>";
		}
		$blob = "<header2>Current preferences<end>\n".
			"<tab>[{$msgOnLink}] [{$msgOffLink}]  Mass messages\n".
			"<tab>[{$invitesOnLink}] [{$invitesOffLink}]  Mass invites\n";
		$prefLink = $this->text->makeBlob("Your current mass message preferences", $blob);

		$sendto->reply($prefLink);
	}

	/**
	 * @HandlesCommand("massmsgs")
	 * @Matches("/^massmsgs$/i")
	 */
	public function massMessagesOverviewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showMassPreferences($sender, $sendto);
	}

	/**
	 * @HandlesCommand("massinvites")
	 * @Matches("/^massinvites$/i")
	 */
	public function massInvitesOverviewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showMassPreferences($sender, $sendto);
	}

	/**
	 * @HandlesCommand("massmsgs")
	 * @Matches("/^massmsgs (off|no|disable)$/i")
	 */
	public function massMessagesOnCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->preferences->save($sender, static::PREF_MSGS, 'no');
		$sendto->reply("You will no longer receive mass messages from this bot.");
	}

	/**
	 * @HandlesCommand("massmsgs")
	 * @Matches("/^massmsgs (on|yes|enable)$/i")
	 */
	public function massMessagesOffCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->preferences->save($sender, static::PREF_MSGS, 'yes');
		$sendto->reply("You will again receive mass messages from this bot.");
	}

	/**
	 * @HandlesCommand("massinvites")
	 * @Matches("/^massinvites (off|no|disable)$/i")
	 */
	public function massInvitesOnCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->preferences->save($sender, static::PREF_INVITES, 'no');
		$sendto->reply("You will no longer receive mass invites from this bot.");
	}

	/**
	 * @HandlesCommand("massinvites")
	 * @Matches("/^massinvites (on|yes|enable)$/i")
	 */
	public function massInvitesOffCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->preferences->save($sender, static::PREF_INVITES, 'yes');
		$sendto->reply("You will again receive mass invites from this bot.");
	}

	/**
	 * @NewsTile("massmsg-settings")
	 * @Description("Shows your current settings for mass messages and -invites
	 * as well with links to change these")
	 */
	public function massMsgNewsTile(string $sender, callable $callback): void {
		$msgs = $this->preferences->get($sender, static::PREF_MSGS);
		$invs = $this->preferences->get($sender, static::PREF_INVITES);
		$msgOnLink      = $this->text->makeChatcmd("On", "/tell <myname> massmsgs on");
		$msgOffLink     = $this->text->makeChatcmd("Off", "/tell <myname> massmsgs off");
		$invitesOnLink  = $this->text->makeChatcmd("On", "/tell <myname> massinvites on");
		$invitesOffLink = $this->text->makeChatcmd("Off", "/tell <myname> massinvites off");
		if ($msgs === "no") {
			$msgOffLink = "<red>Off<end>";
		} else {
			$msgOnLink = "<green>On<end>";
		}
		if ($invs === "no") {
			$invitesOffLink = "<red>Off<end>";
		} else {
			$invitesOnLink = "<green>On<end>";
		}
		$blob = "<header2>Mass messages<end>\n".
			"<tab>[{$msgOnLink}] [{$msgOffLink}]  Receive Mass messages\n".
			"<tab>[{$invitesOnLink}] [{$invitesOffLink}]  Receive Mass invites\n";
		$callback($blob);
	}
}
