<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Attributes as NCA;
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
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "extauth",
		accessLevel: "all",
		description: "Link an AO account with a Discord user",
		help: "extauth.txt"
	)
]
class DiscordGatewayCommandHandler {
	public const DB_TABLE = "discord_mapping_<myname>";

	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	public DiscordGatewayController $discordGatewayController;

	#[NCA\Inject]
	public DiscordRelayController $discordRelayController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "discord_process_commands",
			description: "Process commands sent on Discord",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "discord_unknown_cmd_errors",
			description: "Show a message for unknown commands on Discord",
			mode: "edit",
			type: "options",
			value: "1",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "discord_symbol",
			description: "Discord command prefix symbol",
			mode: "edit",
			type: "text",
			value: "!",
			options: "!;#;*;@;$;+;-",
		);
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

	#[NCA\HandlesCommand("extauth")]
	public function extAuthAccept(CmdContext $context, #[NCA\Str("accept")] string $action, string $uid): void {
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

	#[NCA\HandlesCommand("extauth")]
	public function extAuthRejectCommand(CmdContext $context, #[NCA\Str("reject")] string $action, string $uid): void {
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

	#[NCA\HandlesCommand("extauth")]
	public function extAuthCommand(CmdContext $context, #[NCA\Str("request")] string $action, PCharacter $char): void {
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
			function(DiscordUser $user) use ($context, $name, $uid) {
				$context->char->name = $user->username . "#" . $user->discriminator;
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
	 */
	#[NCA\Event(
		name: "discordmsg",
		description: "Handle commands from Discord private messages"
	)]
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
	 */
	#[NCA\Event(
		name: "discordpriv",
		description: "Handle commands from Discord channel messages"
	)]
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
