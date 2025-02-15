<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use function Safe\preg_match;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	Attributes\Parameter\Str,
	CmdContext,
	CommandManager,
	DB,
	ModuleInstance,
	Modules\BAN\BanController,
	Modules\DISCORD\DiscordAPIClient,
	Nadybot,
	ParamClass\PCharacter,
	Registry,
	Routing\Source,
	Text,
	Types\AccessLevelProvider,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'extauth',
		accessLevel: 'all',
		description: 'Link an AO account with a Discord user',
	)
]
class DiscordGatewayCommandHandler extends ModuleInstance implements AccessLevelProvider {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private DiscordGatewayController $discordGatewayController;

	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);
		$this->commandManager->registerSource(Source::DISCORD_MSG . '(*)');
		$this->commandManager->registerSource(Source::DISCORD_PRIV . '(*)');
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
					return 'guest';
				}
			}
		}
		return null;
	}

	public function getNameForDiscordId(string $discordId): ?string {
		return $this->db->table(DiscordMapping::getTable())
			->where('discord_id', $discordId)
			->whereNotNull('confirmed')
			->firstObj(DiscordMapping::class)
			?->name;
	}

	/** Accept to be linked with a Discord account */
	#[NCA\HandlesCommand('extauth')]
	public function extAuthAccept(CmdContext $context, #[Str('accept')] string $action, string $uid): void {
		if (!$context->isDM()) {
			return;
		}
		$uid = strtoupper($uid);

		$data = $this->db->table(DiscordMapping::getTable())
			->where('name', $uid)
			->whereNotNull('confirmed')
			->firstObj(DiscordMapping::class);
		if ($data !== null) {
			$msg = "You have already linked your account with <highlight>{$data->discord_id}<end>.";
			$context->reply($msg);
			return;
		}

		$data = $this->db->table(DiscordMapping::getTable())
			->where('name', $context->char->name)
			->where('token', $uid)
			->firstObj(DiscordMapping::class);
		if ($data === null) {
			$msg = 'There is currently no request to link with this token.';
			$context->reply($msg);
			return;
		}
		$this->db->table(DiscordMapping::getTable())
			->where('name', $context->char->name)
			->where('token', $uid)
			->update([
				'confirmed' => time(),
				'token' => null,
			]);
		$guilds = $this->discordGatewayController->getGuilds();
		$guild = $guilds[array_keys($guilds)[0]] ?? null;
		if (isset($guild)) {
			$this->discordGatewayController->handleAccountLinking($guild->id, $data->discord_id, $context->char->name);
		}
		$msg = 'You have linked your accounts successfully.';
		$context->reply($msg);
	}

	/** Reject to be linked with a Discord account */
	#[NCA\HandlesCommand('extauth')]
	public function extAuthRejectCommand(CmdContext $context, #[Str('reject')] string $action, string $uid): void {
		if (!$context->isDM()) {
			return;
		}
		$uid = strtoupper($uid);
		$this->db->table(DiscordMapping::getTable())
			->where('token', $uid)
			->where('name', $context->char->name)
			->delete();
		$msg = 'The request has been rejected.';
		$context->reply($msg);
	}

	/**
	 * Request to be linked with an AO character &lt;char&gt;
	 *
	 * Follow the instructions you received on your AO character
	 */
	#[NCA\HandlesCommand('extauth')]
	#[NCA\Help\Epilogue(
		"<header2>Be careful:<end>\n\n".
		"Linking your Discord user with an AO character effectively\n".
		'gives the Discord user the same rights!'
	)]
	public function extAuthCommand(
		CmdContext $context,
		#[Str('request')] string $action,
		PCharacter $char
	): void {
		$discordUserId = $context->char->name;
		if (($authedAs = $this->getNameForDiscordId($discordUserId)) !== null) {
			$msg = "You are already linked to <highlight>{$authedAs}<end>.";
			$context->reply($msg);
			return;
		}
		$name = $char();

		$uid = $this->chatBot->getUid($name);
		if (!isset($uid)) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$data = $this->db->table(DiscordMapping::getTable())
			->where('name', $name)
			->whereNotNull('confirmed')
			->firstObj(DiscordMapping::class);
		if ($data !== null) {
			$msg = "<highlight>{$name}<end> is already linked with a different Discord user.";
			$context->reply($msg);
			return;
		}

		$data = $this->db->table(DiscordMapping::getTable())
			->where('name', $name)
			->where('discord_id', $discordUserId)
			->firstObj(DiscordMapping::class);
		// Never tried to link before
		if ($data === null) {
			$uid = strtoupper(bin2hex(random_bytes(16)));
			$this->db->insert(new DiscordMapping(
				name: $name,
				discord_id: $discordUserId,
				token: $uid,
				created: time(),
			));
		} else {
			$uid = $data->token;
		}

		$user = $this->discordAPIClient->getUser($discordUserId);
		$context->char->name = $user->getName();
		$blob = "The Discord user <highlight>{$context->char->name}<end> has requested to be linked with your ".
			'game account. If you confirm the link, that discord user will be linked '.
			'with this account, be able to run the same commands and have the same rights '.
			"as you.\n".
			"If you haven't requested this link, then <red>reject<end> it!\n".
			"\n".
			'['.
				Text::makeChatcmd('Accept', "/tell <myname> extauth accept {$uid}").
			']    '.
			'['.
				Text::makeChatcmd('Reject', "/tell <myname> extauth reject {$uid}").
			']';
		$msg = Text::makeBlob("Request to link your account with {$context->char->name}", $blob);
		$msg = "You have received a {$msg}.";
		$this->chatBot->sendMassTell($msg, $name);

		$context->reply(
			"I sent a tell to {$name} on Anarchy Online. ".
			'Follow the instructions there to finish linking these 2 accounts.'
		);
	}

	/** Handle an incoming discord private message */
	#[NCA\HandlesEvent(
		name: 'discordmsg',
		description: 'Handle commands from Discord private messages'
	)]
	public function processDiscordDirectMessage(DiscordMessageEvent $event): void {
		$discordUserId = $event->discord_message->author->id ?? $event->sender;
		$context = new CmdContext(
			charName: $discordUserId,
			source: Source::DISCORD_MSG . "({$discordUserId})",
			message: $event->message,
		);
		$this->processDiscordMessage($event, $context);
	}

	/** Handle an incoming discord channel message */
	#[NCA\HandlesEvent(
		name: 'discordpriv',
		description: 'Handle commands from Discord channel messages'
	)]
	public function processDiscordChannelMessage(DiscordMessageEvent $event): void {
		$discordUserId = $event->discord_message->author->id ?? $event->sender;
		$context = new CmdContext(
			charName: $discordUserId,
			source: Source::DISCORD_PRIV . "({$event->discord_message->channel_id})",
			message: $event->message,
		);
		$this->processDiscordMessage($event, $context);
	}

	protected function processDiscordMessage(DiscordMessageEvent $event, CmdContext $context): void {
		$discordUserId = $event->discord_message->author->id ?? $event->sender;
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
		$execCmd = function () use ($context, $sendto): void {
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
		$uid = $this->chatBot->getUid($userId);
		if (isset($uid) && $this->banController->isOnBanlist($uid)) {
			return;
		}
		$context->char->id = $uid;
		$execCmd();
	}
}
