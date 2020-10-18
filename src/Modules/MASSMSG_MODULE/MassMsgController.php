<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use Nadybot\Core\{
	BuddylistManager,
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
	Text,
	DBSchema\Member,
	Modules\PREFERENCES\Preferences,
};

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
	public const SENT    = 'sent';

	public const PREF = 'massmsgs';

	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

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
		$blob = "<header2>Not interested?<end>\n".
			"If you don't want this bot to send mass messages or invites to you, ".
			"you can choose to ".
			$this->text->makeChatcmd(
				"stop receiving them",
				"/tell <myname> massmsgs off"
			) . ".";
		$blob .= "\n\n".
			"If you accidentally turned them off, you can just ".
			$this->text->makeChatcmd(
				"turn them on again",
				"/tell <myname> massmsgs on"
			) . ".";
		return $this->text->makeBlob("change preferences", $blob, "Change your mass message preferences");
	}

	/**
	 * @HandlesCommand("massmsg")
	 * @Matches("/^massmsg (.+)$/i")
	 */
	public function massMsgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$message = "<highlight>Message from {$sender}<end>: ".
			$this->settingManager->getString('massmsg_color') . $args[1] . "<end> :: ".
			$this->getMassMsgOptInOutBlob();
		$this->chatBot->sendPrivate($message, true);
		$result = $this->massCallback(
			function(string $name) use ($message) {
				$this->chatBot->sendMassTell($message, $name);
			}
		);
		$msg = $this->getMassResultPopup($result);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("massinv")
	 * @Matches("/^massinv (.+)$/i")
	 */
	public function massInvCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$message = "<highlight>Invite from {$sender}<end>: ".
			$this->settingManager->getString('massmsg_color') . $args[1] . "<end> :: ".
			$this->getMassMsgOptInOutBlob();
		$this->chatBot->sendPrivate($message, true);
		$result = $this->massCallback(
			function(string $name) use ($message) {
				$this->chatBot->sendMassTell($message, $name);
				$this->chatBot->privategroup_invite($name);
			}
		);
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
		$numBlocked = 0;
		foreach ($result as $player => $action) {
			$blob .= "<tab>$player: ";
			if ($action === static::BLOCKED) {
				$blob .= "<red>blocked by preferences<end>";
				$numBlocked++;
			} elseif ($action === static::IN_CHAT) {
				$blob .= "<green>read in chat<end>";
				$numInChat++;
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
			$person($numInChat) . " in the private channel. ".
			"{$numBlocked} " . $person($numBlocked) . " " . $isAre($numBlocked).
			" blocking mass messages";
		$parts = (array)$this->text->makeBlob("Messaging details", $blob);
		foreach ($parts as &$part) {
			$part = "$msg :: $part";
		}
		return  $parts;
	}

	/**
	 * Run a callback for all users that are members, online but not in
	 * our private channel.
	 * @return array<string,string> array(name => status)
	 */
	protected function massCallback(callable $callback): array {
		$sql = "SELECT * FROM members_<myname>";
		/** @var Member[] */
		$members = $this->db->fetchAll(Member::class, $sql);
		$result = [];
		foreach ($members as $member) {
			if ($member->name === $this->chatBot->vars["name"]
				|| $this->buddylistManager->isOnline($member->name) !== true) {
				continue;
			}
			if (isset($this->chatBot->chatlist[$member->name])) {
				$result[$member->name]  = static::IN_CHAT;
				continue;
			}
			if ($this->preferences->get($member->name, static::PREF) === 'no') {
				$result[$member->name]  = static::BLOCKED;
			} else {
				$callback($member->name);
				$result[$member->name]  = static::SENT;
			}
		}
		return $result;
	}

	/**
	 * @HandlesCommand("massmsgs")
	 * @Matches("/^massmsgs (off|no|disable)$/i")
	 */
	public function massMessagesOnCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->preferences->save($sender, static::PREF, 'no');
		$sendto->reply("You will no longe receive mass messages or mass invites from this bot.");
	}

	/**
	 * @HandlesCommand("massmsgs")
	 * @Matches("/^massmsgs (on|yes|enable)$/i")
	 */
	public function massMessagesOffCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->preferences->save($sender, static::PREF, 'yes');
		$sendto->reply("You will again receive mass messages or mass invites from this bot.");
	}
}
