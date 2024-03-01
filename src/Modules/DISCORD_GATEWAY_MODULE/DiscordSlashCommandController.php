<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use function Amp\call;
use function Safe\preg_split;

use Amp\Promise;
use Closure;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\Modules\DISCORD\DiscordException;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DB,
	DBSchema\CmdCfg,
	LoggerWrapper,
	MessageHub,
	ModuleInstance,
	Modules\DISCORD\DiscordAPIClient,
	Modules\DISCORD\DiscordChannel,
	Nadybot,
	ParamClass\Base,
	ParamClass\PRemove,
	Registry,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	SubcommandManager,
	Text,
	UserException,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\{
	ApplicationCommand,
	ApplicationCommandOption,
	Interaction,
};
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "discord slash-commands",
		accessLevel: "mod",
		description: "Manage the exposed Discord slash-commands",
	),
]
class DiscordSlashCommandController extends ModuleInstance {
	public const DB_SLASH_TABLE = "discord_slash_command_<myname>";

	/** Slash-commands are disabled */
	public const SLASH_OFF = 0;

	/** Slash-commands are treated like regular commands and shown to everyone */
	public const SLASH_REGULAR = 1;

	/** Slash-commands are only shown to the sender */
	public const SLASH_EPHEMERAL = 2;

	public const APP_TYPE_NO_PARAMS = 0;
	public const APP_TYPE_OPT_PARAMS = 1;
	public const APP_TYPE_REQ_PARAMS = 2;

	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\Inject]
	public SubcommandManager $subcmdManager;

	#[NCA\Inject]
	public DiscordAPIClient $api;

	#[NCA\Inject]
	public DiscordGatewayController $gw;

	#[NCA\Inject]
	public DiscordGatewayCommandHandler $gwCmd;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** How to handle Discord Slash-commands */
	#[NCA\Setting\Options(options: [
		"Disable" => 0,
		"Treat them like regular commands" => 1,
		"Make request and reply private" => 2,
	])]
	public int $discordSlashCommands = self::SLASH_EPHEMERAL;

	/** If the state changes to/from disabled, then we need to re-register the slash-cmds */
	#[NCA\SettingChangeHandler('discord_slash_commands')]
	public function syncSlashCmdsOnStateChange(string $settingName, string $oldValue, string $newValue): void {
		if ((int)$oldValue !== self::SLASH_OFF && (int)$newValue !== self::SLASH_OFF) {
			return;
		}
		Promise\rethrow($this->syncSlashCommands());
	}

	/**
	 * Make sure, all slash-commands that the bot has configured, are registered
	 *
	 * @return Promise<void>
	 */
	public function syncSlashCommands(): Promise {
		return call(function (): Generator {
			$appId = $this->gw->getID();
			if (!isset($appId)) {
				return;
			}

			/** @var ApplicationCommand[] */
			$registeredCommands = yield $this->api->getGlobalApplicationCommands($appId);
			yield $this->updateSlashCommands($registeredCommands);
		});
	}

	/**
	 * Calculate which slash-commands should be enabled
	 * and return them as an array of ApplicationCommands
	 *
	 * @return ApplicationCommand[]
	 */
	public function calcSlashCommands(): array {
		$enabledCommands = $this->db->table(self::DB_SLASH_TABLE)
			->pluckStrings("cmd")->toArray();
		if ($this->discordSlashCommands === self::SLASH_OFF) {
			return [];
		}

		/** @var ApplicationCommand[] */
		$cmds = [];
		$cmdDefs = $this->getCmdDefinitions(...$enabledCommands);
		foreach ($cmdDefs as $cmdCfg) {
			if (($appCmd = $this->getApplicationCommandForCmdCfg($cmdCfg)) !== null) {
				$cmds []= $appCmd;
			}
		}
		return $cmds;
	}

	/** Show all currently exposed Discord slash-commands */
	#[NCA\HandlesCommand("discord slash-commands")]
	public function listDiscordSlashCommands(
		CmdContext $context,
		#[NCA\Str("slash")]
		string $action,
		#[NCA\Str("list")]
		?string $subAction
	): void {
		$cmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckStrings("cmd");
		$lines = $cmds->map(function (string $cmd): string {
			$delCommand = $this->text->makeChatcmd(
				"remove",
				"/tell <myname> discord slash rem {$cmd}",
			);
			return "<tab>{$cmd} [{$delCommand}]";
		});
		if ($lines->isEmpty()) {
			$context->reply("Registered Slash-commands (0)");
			return;
		}
		$blob = "<header2>Currently registered Slash-commands<end>\n".
			$lines->join("\n");
		$context->reply($this->text->makeBlob(
			"Registered Slash-commands (" . $lines->count() . ")",
			$blob
		));
	}

	/** Add one or more commands to the list of Discord slash-commands */
	#[NCA\HandlesCommand("discord slash-commands")]
	public function addDiscordSlashCommands(
		CmdContext $context,
		#[NCA\Str("slash")]
		string $action,
		#[NCA\Str("add")]
		string $subAction,
		#[NCA\PWord]
		string ...$commands,
	): Generator {
		$cmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckStrings("cmd")
			->toArray();
		$newCommands = (new Collection($commands))
			->map(function (string $cmd): string {
				return strtolower($cmd);
			})
			->unique()
			->filter(function (string $cmd) use ($cmds): bool {
				return !in_array($cmd, $cmds);
			});
		$illegalCommands = $newCommands
			->filter(function (string $cmd): bool {
				foreach ($this->cmdManager->commands as $permSet => $cmds) {
					if (isset($cmds[$cmd])) {
						return false;
					}
				}
				return true;
			});
		if ($illegalCommands->isNotEmpty()) {
			$msg = "The following command doesn't exist or is not enabled: %s";
			if ($illegalCommands->count() !== 1) {
				$msg = "The following commands don't exist or aren't enabled: %s";
			}
			$errors = $this->text->arraySprintf("<highlight>%s<end>", ...$illegalCommands->toArray());
			$context->reply(sprintf($msg, $this->text->enumerate(...$errors)));
			return;
		}
		if ($newCommands->isEmpty()) {
			$context->reply("All given commands are already exposed as Slash-commands.");
			return;
		}
		if (count($cmds) + $newCommands->count() > 100) {
			$context->reply("You can only expose a total of 100 commands.");
			return;
		}
		$cmdText = $newCommands->containsOneItem() ? "command" : "commands";
		if (!$this->db->table(self::DB_SLASH_TABLE)
			->insert(
				$newCommands->map(function (string $cmd): array {
					return ["cmd" => $cmd];
				})->toArray()
			)
		) {
			$context->reply("There was an error registering the {$cmdText}.");
			return;
		}
		$context->reply("Trying to add " . $newCommands->count() . " {$cmdText}...");
		try {
			yield $this->syncSlashCommands();
		} catch (Throwable $e) {
			$this->db->table(self::DB_SLASH_TABLE)
				->whereIn("cmd", $newCommands->toArray())
				->delete();
			$context->reply(
				"Error registering " . $newCommands->count(). " new ".
				"Slash-{$cmdText}: " . $e->getMessage()
			);
			return;
		}
		$context->reply(
			"Successfully registered " . $newCommands->count(). " new ".
			"Slash-{$cmdText}."
		);
	}

	/** Remove one or more commands from the list of Discord slash-commands */
	#[NCA\HandlesCommand("discord slash-commands")]
	public function remDiscordSlashCommands(
		CmdContext $context,
		#[NCA\Str("slash")]
		string $action,
		PRemove $subAction,
		#[NCA\PWord]
		string ...$commands,
	): Generator {
		$cmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckStrings("cmd")
			->toArray();
		$delCommands = (new Collection($commands))
			->map(function (string $cmd): string {
				return strtolower($cmd);
			})
			->unique()
			->filter(function (string $cmd) use ($cmds): bool {
				return in_array($cmd, $cmds);
			});
		if ($delCommands->isEmpty()) {
			$context->reply("None of the given commands are currently exposed as Slash-commands.");
			return;
		}
		$cmdText = $delCommands->containsOneItem() ? "command" : "commands";
		$this->db->table(self::DB_SLASH_TABLE)
			->whereIn("cmd", $delCommands->toArray())
			->delete();
		$context->reply("Trying to remove " . $delCommands->count() . " {$cmdText}...");
		try {
			yield $this->syncSlashCommands();
		} catch (Throwable $e) {
			$this->db->table(self::DB_SLASH_TABLE)
				->insert(
					$delCommands->map(function (string $cmd): array {
						return ["cmd" => $cmd];
					})->toArray()
				);
			$context->reply(
				"Error removing " . $delCommands->count(). " ".
				"Slash-{$cmdText}: " . $e->getMessage()
			);
			return;
		}
		$context->reply(
			"Successfully removed " . $delCommands->count(). " ".
			"Slash-{$cmdText}."
		);
	}

	/** Pick commands to add to the list of Discord slash-commands */
	#[NCA\HandlesCommand("discord slash-commands")]
	public function pickDiscordSlashCommands(
		CmdContext $context,
		#[NCA\Str("slash")]
		string $action,
		#[NCA\Str("pick")]
		string $subAction,
	): void {
		/** @var string[] */
		$exposedCmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckStrings("cmd")
			->toArray();

		/** @var Collection<CmdCfg> */
		$cmds = new Collection($this->cmdManager->getAll(false));

		/** @var Collection<string> */
		$parts = $cmds
			->sortBy("module")
			->filter(function (CmdCfg $cmd) use ($exposedCmds): bool {
				return !in_array($cmd->cmd, $exposedCmds);
			})->groupBy("module")
			->map(function (Collection $cmds, string $module): string {
				$lines = $cmds->sortBy("cmd")->map(function (CmdCfg $cmd): string {
					$addLink = $this->text->makeChatcmd(
						"add",
						"/tell <myname> discord slash add {$cmd->cmd}"
					);
					return "<tab>[{$addLink}] <highlight>{$cmd->cmd}<end>: {$cmd->description}";
				});
				return "<pagebreak><header2>{$module}<end>\n".
					$lines->join("\n");
			});
		$blob = $parts->join("\n\n");
		$context->reply($this->text->makeBlob(
			"Pick from available commands (" . $cmds->count() . ")",
			$blob,
		));
	}

	/** Handle an incoming discord channel message */
	#[NCA\Event(
		name: "discord(interaction_create)",
		description: "Handle Discord slash commands"
	)]
	public function handleSlashCommands(DiscordGatewayEvent $event): Generator {
		$this->logger->info("Received interaction on Discord");
		$interaction = new Interaction();
		$interaction->fromJSON($event->payload->d);
		$this->logger->debug("Interaction decoded", [
			"interaction" => $interaction,
		]);
		if (!$this->gw->isMe($interaction->application_id)) {
			$this->logger->info("Interaction is not for this bot");
			return;
		}
		$discordUserId = $interaction->user->id ?? $interaction->member->user->id ?? null;
		if (!isset($discordUserId)) {
			$this->logger->info("Interaction has no user id set");
			return;
		}
		if ($interaction->type === $interaction::TYPE_APPLICATION_COMMAND
			&& $this->discordSlashCommands === self::SLASH_OFF) {
			$this->logger->info("Ignoring disabled slash-command");
			return;
		}
		if ($interaction->type !== $interaction::TYPE_APPLICATION_COMMAND
			&& $interaction->type !== $interaction::TYPE_MESSAGE_COMPONENT) {
			$this->logger->info("Ignoring unuspported interaction type");
			return;
		}
		$context = new CmdContext($discordUserId);
		$context->setIsDM(isset($interaction->user));
		$cmd = $interaction->toCommand();
		if (!isset($cmd)) {
			$this->logger->info("No command to execute found in interaction");
			return;
		}
		$context->message = $cmd;
		if (isset($interaction->channel_id)) {
			$channel = $this->gw->getChannel($interaction->channel_id);
			if (!isset($channel)) {
				$this->logger->info("Interaction is for an unknown channel");
				return;
			}
			$context->source = Source::DISCORD_PRIV . "({$channel->name})";
			$cmdMap = $this->cmdManager->getPermsetMapForSource($context->source);
			if (!isset($cmdMap)) {
				$this->logger->info("No permission set found for {source}", [
					"source" => $context->source,
				]);
				$context->source = Source::DISCORD_PRIV . "({$channel->id})";
				$cmdMap = $this->cmdManager->getPermsetMapForSource($context->source);
			}
		} else {
			$context->source = Source::DISCORD_MSG . "({$discordUserId})";
			$cmdMap = $this->cmdManager->getPermsetMapForSource($context->source);
		}
		if (!isset($cmdMap)) {
			$this->logger->info("No permission set found for {source}", [
				"source" => $context->source,
			]);
			return;
		}
		$context->message = $cmdMap->symbol . $context->message;
		yield $this->executeSlashCommand($interaction, $context);
	}

	/**
	 * Ensure the global application commands are identical to $registeredCmds
	 *
	 * @param ApplicationCommand[] $registeredCmds
	 *
	 * @return Promise<void>
	 */
	private function updateSlashCommands(array $registeredCmds): Promise {
		return call(function () use ($registeredCmds): Generator {
			$this->logger->info("{count} Slash-commands already registered", [
				"count" => count($registeredCmds),
			]);
			$registeredCmds = new Collection($registeredCmds);
			$commands = new Collection($this->calcSlashCommands());

			$numModifiedCommands = $this->getNumChangedSlashCommands($registeredCmds, $commands);
			$this->logger->info("{count} Slash-commands need (re-)registering", [
				"count" => $numModifiedCommands,
			]);

			if ($registeredCmds->count() === $commands->count() && $numModifiedCommands === 0) {
				$this->logger->info("No Slash-commands need (re-)registering or deletion");
				return;
			}
			yield $this->setSlashCommands($commands);
		});
	}

	/**
	 * Set the given slash commands without checking if they've changed
	 *
	 * @param Collection<ApplicationCommand> $modifiedCommands
	 *
	 * @return Promise<void>
	 */
	private function setSlashCommands(Collection $modifiedCommands): Promise {
		return call(function () use ($modifiedCommands): Generator {
			$appId = $this->gw->getID();
			if (!isset($appId)) {
				throw new UserException("Currently not connected to Discord, try again later.");
			}
			$cmds = $modifiedCommands->toArray();
			try {
				$newCmds = yield $this->api->registerGlobalApplicationCommands(
					$appId,
					$this->api->encode($cmds)
				);
			} catch (DiscordException $e) {
				if ($e->getCode() === 403) {
					throw new UserException("The Discord bot lacks the right to manage slash commands.");
				}
				throw $e;
			}
			$this->logger->notice(
				count($newCmds) . " Slash-commands registered successfully."
			);
		});
	}

	/** @return array<string,CmdCfg> */
	private function getCmdDefinitions(string ...$commands): array {
		$cfgs = $this->db->table(CommandManager::DB_TABLE)
			->whereIn("cmd", $commands)
			->orWhereIn("dependson", $commands)
			->asObj(CmdCfg::class);

		/** @var Collection<string,CmdCfg> */
		$mains = $cfgs->where("cmdevent", "cmd")
			->keyBy("cmd");
		$cfgs->where("cmdevent", "subcmd")
			->each(function (CmdCfg $cfg) use ($mains): void {
				if (!$mains->has($cfg->dependson)) {
					return;
				}
				$mains->get($cfg->dependson)->file .= ",{$cfg->file}";
			});
		return $mains->toArray();
	}

	/** Get the ApplicationCommand-definition for a single NCA\DefineCommand */
	private function getApplicationCommandForCmdCfg(CmdCfg $cmdCfg): ?ApplicationCommand {
		$cmd = new ApplicationCommand();
		$cmd->type = $cmd::TYPE_CHAT_INPUT;
		$cmd->name = $cmdCfg->cmd;
		$cmd->description = $cmdCfg->description;

		/** @var int[] */
		$types = [];
		$methods = explode(",", $cmdCfg->file);
		foreach ($methods as $methodDef) {
			[$class, $method, $line] = preg_split("/[.:]/", $methodDef);
			$obj = Registry::getInstance($class);
			if (!isset($obj)) {
				continue;
			}
			$refMethod = new ReflectionMethod($obj, $method);
			$type = $this->getApplicationCommandOptionType($refMethod);
			if (isset($type)) {
				$types []= $type;
			}
		}
		if (empty($types)) {
			return null;
		}
		if (count($types) === 1 && $types[0] === 0) {
			return $cmd;
		}

		$option = new ApplicationCommandOption();
		$option->name = "parameters";
		$option->description = "Parameters for this command";
		$option->type = $option::TYPE_STRING;
		$option->required = min($types) === self::APP_TYPE_REQ_PARAMS;
		$cmd->options = [$option];

		return $cmd;
	}

	/**
	 * Calculate if the given command doesn't require parameters (0), has
	 * optional parameters (1) or mandatory parameters (2)
	 *
	 * @phpstan-return null|self::APP_TYPE_*
	 */
	private function getApplicationCommandOptionType(ReflectionMethod $refMethod): ?int {
		$params = $refMethod->getParameters();
		if (count($params) === 0
			|| !$params[0]->hasType()) {
			return null;
		}
		$type = $params[0]->getType();
		if (!($type instanceof ReflectionNamedType)
			|| ($type->getName() !== CmdContext::class)) {
			return null;
		}
		if (count($params) === 1) {
			return self::APP_TYPE_NO_PARAMS;
		}

		$type = self::APP_TYPE_OPT_PARAMS;
		for ($i = 1; $i < count($params); $i++) {
			$paramType = $this->getParamOptionType($params[$i], count($params));
			if ($paramType === null) {
				return null;
			}
			$type = max($type, $paramType);
		}
		return $type;
	}

	/**
	 * Calculate if the given parameter is optional(1) or mandatory(2)
	 *
	 * @phpstan-return null|self::APP_TYPE_OPT_PARAMS|self::APP_TYPE_REQ_PARAMS
	 */
	private function getParamOptionType(ReflectionParameter $param, int $numParams): ?int {
		if (!$param->hasType()) {
			return null;
		}
		$type = $param->getType();
		if (!($type instanceof ReflectionNamedType)) {
			return null;
		}
		if (!$type->isBuiltin() && !is_subclass_of($type->getName(), Base::class)) {
			return null;
		}
		if ($param->allowsNull()) {
			return self::APP_TYPE_OPT_PARAMS;
		}
		return self::APP_TYPE_REQ_PARAMS;
	}

	/**
	 * Calculate how many commands in $set have change relatively to $live
	 *
	 * @param Collection<ApplicationCommand> $live
	 * @param Collection<ApplicationCommand> $set
	 */
	private function getNumChangedSlashCommands(Collection $live, Collection $set): int {
		$live = $live->keyBy("name");
		$changedOrNewCommands = $set->filter(function (ApplicationCommand $cmd) use ($live): bool {
			return !$live->has($cmd->name)
				|| !$cmd->isSameAs($live->get($cmd->name));
		})->values();
		return $changedOrNewCommands->count();
	}

	/**
	 * Execute the given interaction/slash-command
	 *
	 * @return Promise<void>
	 */
	private function executeSlashCommand(Interaction $interaction, CmdContext $context): Promise {
		return call(function () use ($interaction, $context): Generator {
			$discordUserId = $interaction->user->id ?? $interaction->member->user->id ?? null;
			if ($discordUserId === null) {
				$this->logger->info("Interaction has no user id set");
				return;
			}
			$sendto = new DiscordSlashCommandReply(
				$interaction->application_id,
				$interaction->id,
				$interaction->token,
				$interaction->channel_id,
				$context->isDM(),
			);
			Registry::injectDependencies($sendto);
			$context->sendto = $sendto;
			$sendto->sendStateUpdate();
			$userId = $this->gwCmd->getNameForDiscordId($discordUserId);
			// Create and route an artificial message if slash-commands are
			// treated like regular commands
			if (isset($interaction->channel_id)
				&& $this->discordSlashCommands === self::SLASH_REGULAR
			) {
				$this->gw->lookupChannel(
					$interaction->channel_id,
					Closure::fromCallable([$this, "createAndRouteSlashCmdChannelMsg"]),
					$context,
					$userId ?? $discordUserId
				);
			}

			$this->logger->info("Executing slash-command \"{command}\" from {source}", [
				"command" => $context->message,
				"source" => $context->source,
			]);
			// Do the actual command execution
			$execCmd = function () use ($context): void {
				$this->cmdManager->checkAndHandleCmd($context);
			};
			if (!isset($userId)) {
				$execCmd();
				return;
			}
			$context->char->name = $userId;
			$uid = yield $this->chatBot->getUid2($userId);
			$context->char->id = $uid;
			$execCmd();
		});
	}

	/**
	 * Because slash-command-requests are not messages, we have to create
	 * a message ourselves and route it to the bot - if it was issued on a channel
	 * This is just a message with the command that was given
	 */
	private function createAndRouteSlashCmdChannelMsg(DiscordChannel $channel, CmdContext $context, string $userId): int {
		$this->logger->info("Create and route stub-message for slash-command");
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
		return $this->messageHub->handle($rMessage);
	}
}
