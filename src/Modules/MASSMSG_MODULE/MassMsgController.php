<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	AccessManager,
	BuddylistManager,
	CmdContext,
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
 * @package Nadybot\Modules\MASSMSG_MODULE
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "massmsg",
		accessLevel: "mod",
		description: "Send messages to all bot members online",
		help: "massmsg.txt",
		alias: "massmessage"
	),
	NCA\DefineCommand(
		command: "massmsgs",
		accessLevel: "member",
		description: "Control if you want to receive mass messages",
		help: "massmsg.txt"
	),
	NCA\DefineCommand(
		command: "massinvites",
		accessLevel: "member",
		description: "Control if you want to receive mass invites",
		help: "massmsg.txt"
	),
	NCA\DefineCommand(
		command: "massinv",
		accessLevel: "mod",
		description: "Send invites with a message to all bot members online",
		help: "massmsg.txt",
		alias: "massinvite"
	)
]
class MassMsgController {
	public const BLOCKED = 'blocked';
	public const IN_CHAT = 'in chat';
	public const IN_ORG  = 'in org';
	public const SENT    = 'sent';

	public const PREF_MSGS = 'massmsgs';
	public const PREF_INVITES = 'massinvites';

	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public BanController $banController;

	#[NCA\Inject]
	public Preferences $preferences;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "massmsg_color",
			description: "Color for mass messages/invites",
			mode: "edit",
			type: "color",
			value: "<font color='#FF9999'>",
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
		$prefLink = ((array)$this->text->makeBlob("Preferences", $blob, "Change your mass message preferences"))[0];

		return "[{$prefLink}]";
	}

	#[NCA\HandlesCommand("massmsg")]
	public function massMsgCommand(CmdContext $context, string $message): void {
		$message = "<highlight>Message from {$context->char->name}<end>: ".
			($this->settingManager->getString('massmsg_color')??"<font>") . $message . "<end>";
		$this->chatBot->sendPrivate($message, true);
		$this->chatBot->sendGuild($message, true);
		$message .= " :: " . $this->getMassMsgOptInOutBlob();
		$result = $this->massCallback([
			static::PREF_MSGS => function(string $name) use ($message) {
				$this->chatBot->sendMassTell($message, $name);
			}
		]);
		$msg = $this->getMassResultPopup($result);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("massinv")]
	public function massInvCommand(CmdContext $context, string $message): void {
		$message = "<highlight>Invite from {$context->char->name}<end>: ".
			($this->settingManager->getString('massmsg_color')??"<font>") . $message . "<end>";
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
		$context->reply($msg);
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
			if ($name === $this->chatBot->char->name
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
	protected function showMassPreferences(CmdContext $context): void {
		$character = $context->char->name;
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

		$context->reply($prefLink);
	}

	#[NCA\HandlesCommand("massmsgs")]
	public function massMessagesOverviewCommand(CmdContext $context): void {
		$this->showMassPreferences($context);
	}

	#[NCA\HandlesCommand("massinvites")]
	public function massInvitesOverviewCommand(CmdContext $context): void {
		$this->showMassPreferences($context);
	}

	#[NCA\HandlesCommand("massmsgs")]
	public function massMessagesOnCommand(CmdContext $context, bool $status): void {
		$value = $status ? "yes" : "no";
		$colText = $status ? "<green>again receive<end>" : "<red>no longer receive<end>";
		$this->preferences->save($context->char->name, static::PREF_MSGS, $value);
		$context->reply("You will {$colText} mass messages from this bot.");
	}

	#[NCA\HandlesCommand("massinvites")]
	public function massInvitesOnCommand(CmdContext $context, bool $status): void {
		$value = $status ? "yes" : "no";
		$colText = $status ? "<green>again receive<end>" : "<red>no longer receive<end>";
		$this->preferences->save($context->char->name, static::PREF_INVITES, $value);
		$context->reply("You will {$colText} mass invites from this bot.");
	}

	#[
		NCA\NewsTile(
			name: "massmsg-settings",
			description:
				"Shows your current settings for mass messages and -invites\n".
				"as well with links to change these",
			example:
				"<header2>Mass messages<end>\n".
				"<tab>[<green>On<end>] [<u>Off</u>] Receive Mass messages\n".
				"<tab>[<u>On</u>] [<red>Off<end>] Receive Mass invites"
		)
	]
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
			"<tab>[{$invitesOnLink}] [{$invitesOffLink}]  Receive Mass invites";
		$callback($blob);
	}
}
