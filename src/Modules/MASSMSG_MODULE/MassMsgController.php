<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use AO\Package;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	Config\BotConfig,
	MessageHub,
	ModuleInstance,
	Modules\BAN\BanController,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	Registry,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
	Util,
};
use Safe\DateTime;

/**
 * This class contains all functions necessary for mass messaging
 *
 * @package Nadybot\Modules\MASSMSG_MODULE
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "massmsg",
		accessLevel: "mod",
		description: "Send messages to all bot members online",
		alias: "massmessage"
	),
	NCA\DefineCommand(
		command: "massmsgs",
		accessLevel: "member",
		description: "Control if you want to receive mass messages",
	),
	NCA\DefineCommand(
		command: "massinvites",
		accessLevel: "member",
		description: "Control if you want to receive mass invites",
	),
	NCA\DefineCommand(
		command: "massinv",
		accessLevel: "mod",
		description: "Send invites with a message to all bot members online",
		alias: "massinvite"
	),

	NCA\EmitsMessages("system", "mass-message"),
	NCA\EmitsMessages("system", "mass-invite"),
]
class MassMsgController extends ModuleInstance {
	public const BLOCKED = 'blocked';
	public const IN_CHAT = 'in chat';
	public const IN_ORG  = 'in org';
	public const SENT    = 'sent';

	public const PREF_MSGS = 'massmsgs';
	public const PREF_INVITES = 'massinvites';

	/** Color for mass messages/invites */
	#[NCA\Setting\Color]
	public string $massmsgColor = "#FF9999";

	/** Cooldown between sending 2 mass-messages/-invites */
	#[NCA\Setting\Time(options: ["1s", "30s", "1m", "5m", "15m"])]
	public int $massmsgCooldown = 1;

	/** date and time when the last mass message was sent */
	public ?DateTime $lastMessage;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private Preferences $preferences;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Setup]
	public function setup(): void {
		$massInviteReceiver = new MassMsgReceiver();
		Registry::injectDependencies($massInviteReceiver);
		$this->messageHub->registerMessageReceiver($massInviteReceiver);

		$massInviteReceiver = new MassInviteReceiver();
		Registry::injectDependencies($massInviteReceiver);
		$this->messageHub->registerMessageReceiver($massInviteReceiver);
	}

	public function getMassMsgOptInOutBlob(): string {
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

	public function massMsgRateLimitCheck(): ?string {
		$cooldown = $this->massmsgCooldown;
		$message = null;
		if (isset($this->lastMessage)) {
			$notAllowedBefore = $this->lastMessage->getTimestamp() + $cooldown;
			$now = time();
			if ($notAllowedBefore > $now) {
				return "You have to wait <highlight>".
					$this->util->unixtimeToReadable($notAllowedBefore - $now).
					"<end> before sending another mass-message or -invite.";
			}
		}
		$this->lastMessage = new DateTime();
		return $message;
	}

	/**
	 * Send a mass message to every member of this bot,
	 * except for those who are already in the private channel
	 */
	#[NCA\HandlesCommand("massmsg")]
	#[NCA\Help\Group("massmessaging")]
	public function massMsgCommand(CmdContext $context, string $message): void {
		if (($cooldownMsg = $this->massMsgRateLimitCheck()) !== null) {
			$context->reply($cooldownMsg);
			return;
		}
		$message = "<highlight>Message from {$context->char->name}<end>: ".
			"{$this->massmsgColor}{$message}<end>";
		$rMessage = new RoutableMessage($message);
		$rMessage->prependPath(new Source(
			Source::SYSTEM,
			"mass-message",
			'Mass Message'
		));
		$this->messageHub->handle($rMessage);

		$message .= " :: " . $this->getMassMsgOptInOutBlob();

		/** @var array<string,string> */
		$result = $this->massCallback([
			self::PREF_MSGS => function (string $name) use ($message): void {
				$this->chatBot->sendMassTell($message, $name);
			},
		]);
		$msg = $this->getMassResultPopup($result);
		$context->reply($msg);
	}

	/**
	 * Send a mass message to every member of this bot,
	 * except for those who are already in the private channel
	 * and also invite them to the bot's private channel
	 */
	#[NCA\HandlesCommand("massinv")]
	#[NCA\Help\Group("massmessaging")]
	public function massInvCommand(CmdContext $context, string $message): void {
		if (($cooldownMsg = $this->massMsgRateLimitCheck()) !== null) {
			$context->reply($cooldownMsg);
			return;
		}
		$message = "<highlight>Invite from {$context->char->name}<end>: ".
			"{$this->massmsgColor}{$message}<end>";
		$rMessage = new RoutableMessage($message);
		$rMessage->prependPath(new Source(
			Source::SYSTEM,
			"mass-invite",
			'Mass Invite'
		));
		$this->messageHub->handle($rMessage);
		$message .= " :: " . $this->getMassMsgOptInOutBlob();

		/** @var array<string,string> */
		$result = $this->massCallback([
			self::PREF_MSGS => function (string $name) use ($message): void {
				$this->chatBot->sendMassTell($message, $name);
			},
			self::PREF_INVITES => function (string $name): void {
				if (null === ($uid = $this->chatBot->getUid($name))) {
					return;
				}
				$this->chatBot->sendPackage(
					package: new Package\Out\PrivateChannelInvite(charId: $uid)
				);
			},
		]);
		$msg = $this->getMassResultPopup($result);
		$context->reply($msg);
	}

	/**
	 * Run a callback for all users that are members, online but not in
	 * our private channel.
	 *
	 * @param array<string,callable> $callback
	 *
	 * @phpstan-param array<string,callable(string):void> $callback
	 *
	 * @return array<string,string> array(name => status)
	 */
	public function massCallback(array $callback): array {
		$online = $this->buddylistManager->getOnline();
		$result = [];
		foreach ($online as $name) {
			$uid = $this->chatBot->getUid($name);
			if (!isset($uid) || $this->banController->isOnBanlist($uid)) {
				continue;
			}
			if ($name === $this->config->main->character
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

	/** Show your mass-message and mass-invite preferences */
	#[NCA\HandlesCommand("massmsgs")]
	#[NCA\HandlesCommand("massinvites")]
	#[NCA\Help\Group("massmessaging")]
	public function massMessagesOverviewCommand(CmdContext $context): void {
		$this->showMassPreferences($context);
	}

	/** Enable or disable receiving of mass-messages */
	#[NCA\HandlesCommand("massmsgs")]
	#[NCA\Help\Group("massmessaging")]
	public function massMessagesOnCommand(CmdContext $context, bool $status): void {
		$value = $status ? "yes" : "no";
		$colText = $status ? "<on>again receive<end>" : "<off>no longer receive<end>";
		$this->preferences->save($context->char->name, static::PREF_MSGS, $value);
		$context->reply("You will {$colText} mass messages from this bot.");
	}

	/** Enable or disable receiving of mass-invites */
	#[NCA\HandlesCommand("massinvites")]
	#[NCA\Help\Group("massmessaging")]
	public function massInvitesOnCommand(CmdContext $context, bool $status): void {
		$value = $status ? "yes" : "no";
		$colText = $status ? "<on>again receive<end>" : "<off>no longer receive<end>";
		$this->preferences->save($context->char->name, static::PREF_INVITES, $value);
		$context->reply("You will {$colText} mass invites from this bot.");
	}

	#[
		NCA\NewsTile(
			name: "massmsg-settings",
			description: "Shows your current settings for mass messages and -invites\n".
				"as well with links to change these",
			example: "<header2>Mass messages<end>\n".
				"<tab>[<on>On<end>] [<u>Off</u>] Receive Mass messages\n".
				"<tab>[<u>On</u>] [<off>Off<end>] Receive Mass invites"
		)
	]
	public function massMsgNewsTile(string $sender): ?string {
		$msgs = $this->preferences->get($sender, static::PREF_MSGS);
		$invs = $this->preferences->get($sender, static::PREF_INVITES);
		$msgOnLink      = $this->text->makeChatcmd("On", "/tell <myname> massmsgs on");
		$msgOffLink     = $this->text->makeChatcmd("Off", "/tell <myname> massmsgs off");
		$invitesOnLink  = $this->text->makeChatcmd("On", "/tell <myname> massinvites on");
		$invitesOffLink = $this->text->makeChatcmd("Off", "/tell <myname> massinvites off");
		if ($msgs === "no") {
			$msgOffLink = "<off>Off<end>";
		} else {
			$msgOnLink = "<on>On<end>";
		}
		if ($invs === "no") {
			$invitesOffLink = "<off>Off<end>";
		} else {
			$invitesOnLink = "<on>On<end>";
		}
		$blob = "<header2>Mass messages<end>\n".
			"<tab>[{$msgOnLink}] [{$msgOffLink}]  Receive Mass messages\n".
			"<tab>[{$invitesOnLink}] [{$invitesOffLink}]  Receive Mass invites";
		return $blob;
	}

	/**
	 * Turn the result of a massCallback() into a nice popup
	 *
	 * @param array<string,string> $result
	 *
	 * @return string[]
	 */
	protected function getMassResultPopup(array $result): array {
		ksort($result);
		$blob = "<header2>Result of your mass message<end>\n";
		$numSent = 0;
		$numInChat = 0;
		$numInOrg = 0;
		$numBlocked = 0;
		foreach ($result as $player => $action) {
			$blob .= "<tab>{$player}: ";
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
		$person = function (int $num): string {
			return ($num === 1) ? "person" : "people";
		};
		$isAre = function (int $num): string {
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
			$part = "{$msg} :: {$part}";
		}
		return $parts;
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
			$msgOffLink = "<off>Off<end>";
		} else {
			$msgOnLink = "<on>On<end>";
		}
		if ($invs === "no") {
			$invitesOffLink = "<off>Off<end>";
		} else {
			$invitesOnLink = "<on>On<end>";
		}
		$blob = "<header2>Current preferences<end>\n".
			"<tab>[{$msgOnLink}] [{$msgOffLink}]  Mass messages\n".
			"<tab>[{$invitesOnLink}] [{$invitesOffLink}]  Mass invites\n";
		$prefLink = $this->text->makeBlob("Your current mass message preferences", $blob);

		$context->reply($prefLink);
	}
}
