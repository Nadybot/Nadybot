<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{
	CmdContext,
	CommandManager,
	DB,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	Text,
};
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordUser;
use Nadybot\Core\ParamClass\PCharacter;

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
	public const DB_TABLE = "discord_mapping_<myname>";

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

	/** @Inject */
	public DiscordRelayController $discordRelayController;

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
		$this->settingManager->add(
			$this->moduleName,
			"discord_unknown_cmd_errors",
			"Show a message for unknown commands on Discord",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_symbol",
			"Discord command prefix symbol",
			"edit",
			"text",
			"!",
			"!;#;*;@;$;+;-",
		);
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
	}

	public function getNameForDiscordId(string $discordId): ?string {
		/** @var ?DiscordMapping */
		$data = $this->db->table(self::DB_TABLE)
			->where("discord_id", $discordId)
			->whereNotNull("confirmed")
			->asObj(DiscordMapping::class)
			->first();
		return $data ? $data->name : null;
	}

	/**
	 * @HandlesCommand("extauth")
	 * @Mask $action accept
	 */
	public function extAuthAccept(CmdContext $context, string $action, string $uid): void {
		if (!$context->isDM()) {
			return;
		}
		$uid = strtoupper($uid);
		/** @var ?DiscordMapping */
		$data = $this->db->table(self::DB_TABLE)
			->where("name", $uid)
			->whereNotNull("confirmed")
			->asObj(DiscordMapping::class)
			->first();
		if ($data !== null) {
			$msg = "You have already linked your account with <highlight>{$data->discord_id}<end>.";
			$context->reply($msg);
			return;
		}
		$data = $this->db->table(self::DB_TABLE)
			->where("name", $context->char->name)
			->where("token", $uid)
			->asObj(DiscordMapping::class)
			->first();
		if ($data === null) {
			$msg = "There is currently no request to link with this token.";
			$context->reply($msg);
			return;
		}
		$this->db->table(self::DB_TABLE)
			->where("name", $context->char->name)
			->where("token", $uid)
			->update([
				"confirmed" => time(),
				"token" => null
			]);
		$msg = "You have linked your accounts successfully.";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("extauth")
	 * @Mask $action reject
	 */
	public function extAuthRejectCommand(CmdContext $context, string $action, string $uid): void {
		if (!$context->isDM()) {
			return;
		}
		$uid = strtoupper($uid);
		$this->db->table(self::DB_TABLE)
			->where("token", $uid)
			->where("name", $context->char->name)
			->delete();
		$msg = "The request has been rejected.";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("extauth")
	 * @Mask $action request
	 */
	public function extAuthCommand(CmdContext $context, string $action, PCharacter $char): void {
		$discordUserId = $context->char->name;
		if (($authedAs = $this->getNameForDiscordId($discordUserId)) !== null) {
			$msg = "You are already linked to <highlight>$authedAs<end>.";
			$context->reply($msg);
			return;
		}
		$name = $char();

		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		/** @var ?DiscordMapping */
		$data = $this->db->table(self::DB_TABLE)
			->where("name", $name)
			->whereNotNull("confirmed")
			->asObj(DiscordMapping::class)
			->first();
		if ($data !== null) {
			$msg = "<highlight>{$name}<end> is already linked with a different Discord user.";
			$context->reply($msg);
			return;
		}
		/** @var ?DiscordMapping */
		$data = $this->db->table(self::DB_TABLE)
			->where("name", $name)
			->where("discord_id", $discordUserId)
			->asObj(DiscordMapping::class)
			->first();
		// Never tried to link before
		if ($data === null) {
			$uid = strtoupper(bin2hex(random_bytes(16)));
			$this->db->table(self::DB_TABLE)
				->insert([
					"name" => $name,
					"discord_id" => $discordUserId,
					"token" => $uid,
					"created" => time(),
				]);
		} else {
			$uid = $data->token;
		}
		$this->discordAPIClient->getUser(
			$discordUserId,
			function(DiscordUser $user) use ($context, $name, $discordUserId, $uid) {
				$context->char->name = $user ? $user->username . "#" . $user->discriminator : $discordUserId;
				$blob = "The Discord user <highlight>{$context->char->name}<end> has requested to be linked with your ".
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
				$msg = $this->text->makeBlob("Request to link your account with {$context->char->name}", $blob);
				$msg = $this->text->blobWrap("You have received a ", $msg, ".");
				$this->chatBot->sendMassTell($msg, $name);
			}
		);
		$context->reply(
			"I sent a tell to {$name} on Anarchy Online. ".
			"Follow the instructions there to finish linking these 2 accounts."
		);
	}

	/**
	 * Handle an incoming discord private message
	 *
	 * @Event(name="discordmsg",
	 * 	description="Handle commands from Discord private messages")
	 */
	public function processDiscordDirectMessage(DiscordMessageEvent $event): void {
		$isCommand = substr($event->message??"", 0, 1) === $this->settingManager->get("discord_symbol");
		if ( $isCommand ) {
			$event->message = substr($event->message??"", 1);
		}
		$sendto = new DiscordMessageCommandReply(
			$event->channel,
			true,
			$event->discord_message,
		);
		Registry::injectDependencies($sendto);
		$discordUserId = $event->discord_message->author->id ?? (string)$event->sender;
		$context = new CmdContext($discordUserId);
		$context->channel = "msg";
		$context->message = $event->message;
		$context->sendto = $sendto;
		if (preg_match("/^extauth\s+request/si", $event->message)) {
			$this->commandManager->processCmd($context);
			return;
		}
		$userId = $this->getNameForDiscordId($discordUserId);
		if (!isset($userId)) {
			$this->commandManager->processCmd($context);
			return;
		}
		$context->char->name = $userId;
		$this->chatBot->getUid($userId, function(?int $uid, CmdContext $context): void {
			$context->char->id = $uid;
			$this->commandManager->processCmd($context);
		}, $context);
	}

	/**
	 * Handle an incoming discord channel message
	 *
	 * @Event(name="discordpriv",
	 * 	description="Handle commands from Discord channel messages")
	 */
	public function processDiscordChannelMessage(DiscordMessageEvent $event): void {
		$discordUserId = $event->discord_message->author->id ?? (string)$event->sender;
		$isCommand = substr($event->message, 0, 1) === $this->settingManager->getString("discord_symbol");
		if (
			!$isCommand
			|| strlen($event->message) < 2
			|| !$this->settingManager->getBool('discord_process_commands')
		) {
			return;
		}
		$cmd = strtolower(explode(" ", substr($event->message, 1))[0]);
		$commandHandler = $this->commandManager->getActiveCommandHandler($cmd, "priv", substr($event->message, 1));
		if ($commandHandler === null && !$this->settingManager->getBool('discord_unknown_cmd_errors')) {
			return;
		}
		$sendto = new DiscordMessageCommandReply(
			$event->channel,
			false,
			$event->discord_message,
		);
		Registry::injectDependencies($sendto);
		$discordUserId = $event->discord_message->author->id ?? (string)$event->sender;
		$context = new CmdContext($discordUserId);
		$context->channel = "priv";
		$context->message = substr($event->message, 1);
		$context->sendto = $sendto;
		if (preg_match("/^extauth\s+request/si", $event->message)) {
			$this->commandManager->processCmd($context);
			return;
		}
		$userId = $this->getNameForDiscordId($discordUserId);
		if (!isset($userId)) {
			$this->commandManager->processCmd($context);
			return;
		}
		$context->char->name = $userId;
		$this->chatBot->getUid($userId, function(?int $uid, CmdContext $context): void {
			$context->char->id = $uid;
			$this->commandManager->processCmd($context);
		}, $context);
	}
}
