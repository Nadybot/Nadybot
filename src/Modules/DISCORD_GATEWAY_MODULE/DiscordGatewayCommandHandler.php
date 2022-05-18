<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Closure;
use Nadybot\Core\{
	Attributes as NCA,
	AccessLevelProvider,
	AccessManager,
	CmdContext,
	CommandManager,
	DB,
	MessageHub,
	ModuleInstance,
	LoggerWrapper,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordUser,
	Nadybot,
	ParamClass\PCharacter,
	Routing\Source,
	Registry,
	SettingManager,
	Text,
};
use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\Interaction;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "extauth",
		accessLevel: "all",
		description: "Link an AO account with a Discord user",
	)
]
class DiscordGatewayCommandHandler extends ModuleInstance implements AccessLevelProvider {
	public const DB_TABLE = "discord_mapping_<myname>";
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

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
		$this->accessManager->registerProvider($this);
		$this->commandManager->registerSource(Source::DISCORD_MSG . "(*)");
		$this->commandManager->registerSource(Source::DISCORD_PRIV . "(*)");
	}

	public function getSingleAccessLevel(string $sender): ?string {
		if (!ctype_digit($sender)) {
			return null;
		}
		$guilds = $this->discordGatewayController->getGuilds();
		foreach ($guilds as $guild) {
			foreach ($guild->members as $member) {
				if (!isset($member->user)) {
					continue;
				}
				if ($member->user->id === $sender) {
					return "guest";
				}
			}
		}
		return null;
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

	/** Accept to be linked with a Discord account */
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
		/** @var ?DiscordMapping */
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
		$guilds = $this->discordGatewayController->getGuilds();
		$guild = $guilds[array_keys($guilds)[0]] ?? null;
		if (isset($guild)) {
			$this->discordGatewayController->handleAccountLinking($guild->id, $data->discord_id, $context->char->name);
		}
		$msg = "You have linked your accounts successfully.";
		$context->reply($msg);
	}

	/** Reject to be linked with a Discord account */
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

	/**
	 * Request to be linked with an AO character &lt;char&gt;
	 *
	 * Follow the instructions you received on your AO character
	 */
	#[NCA\HandlesCommand("extauth")]
	#[NCA\Help\Epilogue(
		"<header2>Be careful:<end>\n\n".
		"Linking your Discord user with an AO character effectively\n".
		"gives the Discord user the same rights!"
	)]
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
		$discordUserId = $event->discord_message->author->id ?? (string)$event->sender;
		$context = new CmdContext($discordUserId);
		$context->source = Source::DISCORD_MSG . "({$discordUserId})";
		$context->message = $event->message;
		$this->processDiscordMessage($event, $context);
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
		$context = new CmdContext($discordUserId);
		$context->source = Source::DISCORD_PRIV . "({$event->discord_message->channel_id})";
		$context->message = $event->message;
		$this->processDiscordMessage($event, $context);
	}

	protected function processDiscordMessage(DiscordMessageEvent $event, CmdContext $context): void {
		$discordUserId = $event->discord_message->author->id ?? (string)$event->sender;
		$sendto = new DiscordMessageCommandReply(
			$event->channel,
			false,
			$event->discord_message,
		);
		$context->sendto = $sendto;
		Registry::injectDependencies($sendto);
		if (!preg_match("/^.?extauth\s+request/si", $event->message)) {
			$userId = $this->getNameForDiscordId($discordUserId);
		}
		$execCmd = function() use ($context, $sendto): void {
			if ($this->commandManager->checkAndHandleCmd($context)) {
				return;
			}
			$context->source = $sendto->getChannelName();
			$this->commandManager->checkAndHandleCmd($context);
		};
		if (!isset($userId)) {
			$execCmd();
			return;
		}
		$context->char->name = $userId;
		$this->chatBot->getUid(
			$userId,
			function(?int $uid, CmdContext $context, Closure $execCmd): void {
				$context->char->id = $uid;
				$execCmd();
			},
			$context,
			$execCmd
		);
	}

	/**
	 * Handle an incoming discord channel message
	 */
	#[NCA\Event(
		name: "discord(interaction_create)",
		description: "Handle Discord slash commands"
	)]
	public function processDiscordSlashCommands(DiscordGatewayEvent $event): void {
		$interaction = new Interaction();
		$interaction->fromJSON($event->payload->d);
		if (!$this->discordGatewayController->isMe($interaction->application_id)) {
			return;
		}
		$discordUserId = $interaction->user->id ?? $interaction->member->user->id ?? null;
		if (!isset($discordUserId)) {
			return;
		}
		if ($interaction->type !== $interaction::TYPE_APPLICATION_COMMAND
			&& $interaction->type !== $interaction::TYPE_MESSAGE_COMPONENT) {
			return;
		}
		$context = new CmdContext($discordUserId);
		$context->setIsDM(isset($interaction->user));
		$cmd = $interaction->toCommand();
		if (!isset($cmd)) {
			return;
		}
		$context->message = $cmd;
		if (isset($interaction->channel_id)) {
			$channel = $this->discordGatewayController->getChannel($interaction->channel_id);
			if (!isset($channel)) {
				return;
			}
			$context->source = Source::DISCORD_PRIV . "({$channel->name})";
			$cmdMap = $this->commandManager->getPermsetMapForSource($context->source);
			if (!isset($cmdMap)) {
				$context->source = Source::DISCORD_PRIV . "({$channel->id})";
				$cmdMap = $this->commandManager->getPermsetMapForSource($context->source);
			}
		} else {
			$context->source = Source::DISCORD_MSG . "({$discordUserId})";
			$cmdMap = $this->commandManager->getPermsetMapForSource($context->source);
		}
		if (!isset($cmdMap)) {
			return;
		}
		$context->message = $cmdMap->symbol . $context->message;
		$this->processDiscordSlashCommand($interaction, $context);
	}

	protected function processDiscordSlashCommand(Interaction $interaction, CmdContext $context): void {
		$discordUserId = $interaction->user->id ?? $interaction->member->user->id ?? null;
		if ($discordUserId === null) {
			return;
		}
		$gw = $this->discordGatewayController;
		$sendto = new DiscordSlashCommandReply(
			$interaction->application_id,
			$interaction->id,
			$interaction->token,
			$interaction->channel_id,
			$context->isDM(),
		);
		$context->sendto = $sendto;
		Registry::injectDependencies($sendto);
		$userId = $this->getNameForDiscordId($discordUserId);
		if (isset($interaction->channel_id)
			&& $gw->discordSlashCommands === $gw::SLASH_REGULAR
		) {
			$gw->lookupChannel(
				$interaction->channel_id,
				function (DiscordChannel $channel, CmdContext $context, string $userId): void {
					$rMessage = new RoutableMessage("/" . substr($context->message, 1));
					$rMessage->setCharacter(
						new Character($userId, null, null)
					);
					$rMessage->appendPath(
						new Source(
							Source::DISCORD_PRIV,
							$channel->name ?? $channel->id,
						),
					);
					$this->messageHub->handle($rMessage);
				},
				$context,
				$userId ?? $discordUserId
			);
		}
		$execCmd = function() use ($context): void {
			$this->commandManager->checkAndHandleCmd($context);
		};
		if (!isset($userId)) {
			$execCmd();
			return;
		}
		$context->char->name = $userId;
		$this->chatBot->getUid(
			$userId,
			function(?int $uid, CmdContext $context, Closure $execCmd): void {
				$context->char->id = $uid;
				$execCmd();
			},
			$context,
			$execCmd
		);
	}
}
