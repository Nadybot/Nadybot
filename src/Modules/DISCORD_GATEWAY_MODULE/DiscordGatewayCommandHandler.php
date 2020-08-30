<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{
	CommandManager,
	CommandReply,
	DB,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	Text,
};
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordUser;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'extauth',
 *		accessLevel   = 'all',
 *		description   = 'Link an AO account with a Discord user',
 *		help          = 'extauth.txt'
 *	)
 */
class DiscordGatewayCommandHandler {

	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public DiscordGatewayController $discordGatewayController;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"discord_process_commands",
			"Process commands sent on Discord",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->db->loadSQLFile($this->moduleName, "discord_mapping");
	}

	public function getNameForDiscordId(string $discordId): ?string {
		/** @var ?DiscordMapping */
		$data = $this->db->fetch(
			DiscordMapping::class,
			"SELECT * FROM discord_mapping_<myname> WHERE discord_id=? AND confirmed IS NOT NULL",
			$discordId
		);
		return $data ? $data->name : null;
	}

	/**
	 * @HandlesCommand("extauth")
	 * @Matches("/^extauth accept (.+)$/i")
	 */
	public function extAuthAccept(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== "msg") {
			return;
		}
		$uid = strtoupper($args[1]);
		/** @var ?DiscordMapping */
		$data = $this->db->fetch(
			DiscordMapping::class,
			"SELECT * FROM discord_mapping_<myname> WHERE name=? AND confirmed IS NOT NULL",
			$args[1]
		);
		if ($data !== null) {
			$msg = "You have already linked your account with <highlight>{$data->discord_id}<end>.";
			$sendto->reply($msg);
			return;
		}
		$data = $this->db->fetch(
			DiscordMapping::class,
			"SELECT * FROM discord_mapping_<myname> WHERE name=? AND token=?",
			$sender,
			$uid
		);
		if ($data === null) {
			$msg = "There is currently no request to link with this token.";
			$sendto->reply($msg);
			return;
		}
		$this->db->exec(
			"UPDATE discord_mapping_<myname> ".
			"SET confirmed=?, token=null ".
			"WHERE token=? AND name=?",
			time(),
			$uid,
			$sender
		);
		$msg = "You have linked your accounts successfully.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("extauth")
	 * @Matches("/^extauth reject (.+)$/i")
	 */
	public function extAuthRejectCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== "msg") {
			return;
		}
		$uid = strtoupper($args[1]);
		$this->db-exec(
			"DELETE FROM discord_mapping_<myname> ".
			"WHERE token=? AND name=?",
			$uid,
			$sender
		);
		$msg = "The request has been rejected.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("extauth")
	 * @Matches("/^extauth request (.+)$/i")
	 */
	public function extAuthCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$discordUserId = $sender;
		if ($channel !== "msg") {
			return;
		}
		if (($authedAs = $this->getNameForDiscordId($discordUserId)) !== null) {
			$msg = "You are already linked to <highlight>$authedAs<end>.";
			$sendto->reply($msg);
			return;
		}
		$name = ucfirst(strtolower($args[1]));

		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		/** @var ?DiscordMapping */
		$data = $this->db->fetch(
			DiscordMapping::class,
			"SELECT * FROM discord_mapping_<myname> WHERE name=? AND confirmed IS NOT NULL",
			$args[1]
		);
		if ($data !== null) {
			$msg = "<highlight>$name<end> is already linked with a different Discord user.";
			$sendto->reply($msg);
			return;
		}
		/** @var ?DiscordMapping */
		$data = $this->db->fetch(
			DiscordMapping::class,
			"SELECT * FROM discord_mapping_<myname> WHERE name=? AND discord_id=?",
			$args[1],
			$discordUserId
		);
		// Never tried to link before
		if ($data === null) {
			$uid = strtoupper(unpack("H*", openssl_random_pseudo_bytes(16))[1]);
			$this->db->exec(
				"INSERT INTO discord_mapping_<myname> ".
				"(name, discord_id, token, created) ".
				"VALUES(?, ?, ?, ?)",
				$name,
				$discordUserId,
				$uid,
				time()
			);
		} else {
			$uid = $data->token;
		}
		$this->discordAPIClient->getUser(
			$discordUserId,
			function(DiscordUser $user) use ($name, $discordUserId, $uid) {
				$sender = $user ? $user->username . "#" . $user->discriminator : $discordUserId;
				$blob = "The Discord user <highlight>$sender<end> has requested to be linked with your ".
					"game account. If you confirm the link, that discord user will be linked ".
					"with this account, be able to run the same commands and have the same rights ".
					"as you.\n".
					"If you haven't requested this link, then <red>reject<end> it!\n".
					"\n".
					"[".
						$this->text->makeChatcmd("Accept", "/tell <myname> extauth accept $uid").
					"]    ".
					"[".
						$this->text->makeChatcmd("Reject", "/tell <myname> extauth reject $uid").
					"]";
				$msg = $this->text->makeBlob("Request to link your account with $sender", $blob);
				$this->chatBot->sendTell("You have received a $msg.", $name);
			}
		);
	}

	/**
	 * Handle an incoming discord private message
	 *
	 * @Event("discordmsg")
	 * @Description("Handle commands from Discord private messages")
	 */
	public function processDiscordDirectMessage(DiscordMessageEvent $event): void {
		$isCommand = substr($event->message, 0, 1) === $this->settingManager->get("symbol");
		if ( $isCommand ) {
			$event->message = substr($event->message, 1);
		}
		$sendto = new DiscordMessageCommandReply(
			$event->channel,
		);
		Registry::injectDependencies($sendto);
		$discordUserId = $event->discord_message->author->id;
		$this->commandManager->process(
			"priv",
			$event->message,
			$this->getNameForDiscordId($discordUserId) ?? $discordUserId,
			$sendto
		);
	}

	/**
	 * Handle an incoming discord channel message
	 *
	 * @Event("discordpriv")
	 * @Description("Handle commands from Discord channel messages")
	 */
	public function processDiscordChannelMessage(DiscordMessageEvent $event): void {
		$discordUserId = $event->discord_message->author->id;
		$isCommand = substr($event->message, 0, 1) === $this->settingManager->getString("symbol");
		if ( !$isCommand || strlen($event->message) < 2) {
			return;
		}
		$sendto = new DiscordMessageCommandReply(
			$event->channel,
		);
		Registry::injectDependencies($sendto);
		$this->commandManager->process(
			"priv",
			substr($event->message, 1),
			$this->getNameForDiscordId($discordUserId) ?? $discordUserId,
			$sendto
		);
	}
}
