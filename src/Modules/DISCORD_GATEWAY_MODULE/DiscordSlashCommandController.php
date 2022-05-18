<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use function Safe\json_decode;
use function Safe\json_encode;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Safe\Exceptions\JsonException;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DB,
	DBSchema\CmdCfg,
	HttpResponse,
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
	Text,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\{
	ApplicationCommand,
	ApplicationCommandOption,
	Interaction,
};

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

	#[NCA\Inject]
	public CommandManager $cmdManager;

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

	#[NCA\SettingChangeHandler('discord_slash_commands')]
	public function updateSlashCmdsIfRequires(string $settingName, string $oldValue, string $newValue): void {
		if ((int)$oldValue !== self::SLASH_OFF && (int)$newValue !== self::SLASH_OFF) {
			return;
		}
		$this->registerSlashCommands();
	}

	/**
	 * @phpstan-param null|callable():void $success
	 * @phpstan-param null|callable(string):void $failure
	 */
	public function registerSlashCommands(?callable $success=null, ?callable $failure=null): void {
		$appId = $this->gw->getID();
		if (!isset($appId)) {
			return;
		}
		$this->api->getGlobalApplicationCommands(
			$appId,
			/** @param ApplicationCommand[] $commands */
			function (array $commands) use ($success, $failure): void {
				$this->updateSlashCommands($commands, $success, $failure);
			}
		);
	}

	/**
	 * @param ApplicationCommand[] $registeredCmds
	 * @phpstan-param null|callable():void $success
	 * @phpstan-param null|callable(string):void $failure
	 */
	protected function updateSlashCommands(array $registeredCmds, ?callable $success=null, ?callable $failure=null): void {
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
			if (isset($success)) {
				$success();
			}
			return;
		}
		$this->setSlashCommands($commands, $success, $failure);
	}

	/**
	 * @param Collection<ApplicationCommand> $modifiedCommands
	 * @phpstan-param null|callable():void $success
	 * @phpstan-param null|callable(string):void $failure
	 */
	private function setSlashCommands(
		Collection $modifiedCommands,
		?callable $success=null,
		?callable $failure=null,
	): void {
		if ($modifiedCommands->isEmpty()) {
			if (isset($success)) {
				$success();
			}
			return;
		}
		$appId = $this->gw->getID();
		if (!isset($appId)) {
			if (isset($failure)) {
				$failure("Currently not connected to Discord, try again later.");
			}
			return;
		}
		$cmds = $modifiedCommands->toArray();
		$data = json_encode($cmds);
		$data = preg_replace('/,"[^"]+":null/', '', $data);
		$data = preg_replace('/"[^"]+":null,/', '', $data);
		$data = preg_replace('/"[^"]+":null/', '', $data);
		$this->api->registerGlobalApplicationCommands(
			$appId,
			$data,
			/** @param ApplicationCommand[] $commands */
			function (array $commands) use ($success): void {
				$this->logger->notice(
					count($commands) . " Slash-commands registered successfully."
				);
				if (isset($success)) {
					$success();
				}
			},
			function (HttpResponse $response) use ($failure): bool {
				if (!isset($failure)) {
					return false;
				}
				if ($response->headers["content-type"] !== "application/json") {
					$failure($response->body??"Unknown error");
					return true;
				}
				try {
					$json = json_decode($response->body ?? "null");
				} catch (JsonException) {
					$failure($response->body??"Unknown error");
					return true;
				}
				$failure($json->message??"Unknown error");
				return true;
			}
		);
	}

	/** @return ApplicationCommand[] */
	public function calcSlashCommands(): array {
		$enabledCommands = $this->db->table(self::DB_SLASH_TABLE)
			->pluckAs("cmd", "string")->toArray();
		if ($this->discordSlashCommands === self::SLASH_OFF) {
			$enabledCommands = [];
		}
		/** @var ApplicationCommand[] */
		$cmds = [];
		$objs = Registry::getAllInstances();
		foreach ($objs as $obj) {
			$refClass = new ReflectionClass($obj);
			foreach ($refClass->getAttributes(NCA\DefineCommand::class) as $cmd) {
				/** @var NCA\DefineCommand */
				$cmdObj = $cmd->newInstance();
				if (strpos($cmdObj->command, " ") !== false) {
					continue;
				}
				if (!in_array($cmdObj->command, $enabledCommands)) {
					continue;
				}
				$cmds []= $this->getApplicationCommandForDefineCommand($cmdObj);
			}
		}
		return $cmds;
	}

	private function getApplicationCommandForDefineCommand(NCA\DefineCommand $cmdObj): ApplicationCommand {
		$cmd = new ApplicationCommand();
		$cmd->type = $cmd::TYPE_CHAT_INPUT;
		$cmd->name = $cmdObj->command;
		$cmd->description = $cmdObj->description;
		$objs = Registry::getAllInstances();
		$types = [];
		foreach ($objs as $obj) {
			$refClass = new ReflectionClass($obj);
			foreach ($refClass->getMethods() as $refMethod) {
				foreach ($refMethod->getAttributes(NCA\HandlesCommand::class) as $hc) {
					/** @var NCA\HandlesCommand */
					$hcObj = $hc->newInstance();
					if (explode(" ", $hcObj->command)[0] !== $cmd->name) {
						continue;
					}
					$type = $this->getApplicationCommandOptionType($refMethod);
					if (isset($type)) {
						$types []= $type;
					}
				}
			}
		}
		if (empty($types)) {
			throw new Exception("No validad handlers found for {$cmdObj->command}.");
		}
		if (count($types) === 1 && $types[0] === 0) {
			return $cmd;
		}

		$option = new ApplicationCommandOption();
		$option->name = "parameters";
		$option->description = "Parameters for this command";
		$option->type = $option::TYPE_STRING;
		$option->required = min($types) === 2;
		$cmd->options = [$option];

		return $cmd;
	}

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
			return 0;
		}

		$type = 1;
		for ($i = 1; $i < count($params); $i++) {
			$paramType = $this->getParamOptionType($params[$i], count($params));
			if ($paramType === null) {
				return null;
			}
			$type = max($type, $paramType);
		}
		return $type;
	}

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
			return 1;
		}
		return 2;
	}

	/**
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

	/** Show all currently exposed Discord slash-commands */
	#[NCA\HandlesCommand("discord slash-commands")]
	public function listDiscordSlashCommands(
		CmdContext $context,
		#[NCA\Str("slash")] string $action,
		#[NCA\Str("list")] ?string $subAction
	): void {
		$cmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckAs("cmd", "string");
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
		#[NCA\Str("slash")] string $action,
		#[NCA\Str("add")] string $subAction,
		#[NCA\PWord] string ...$commands,
	): void {
		$cmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckAs("cmd", "string")
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
				$newCommands->map(function(string $cmd): array {
					return ["cmd" => $cmd];
				})->toArray()
			)
		) {
			$context->reply("There was an error registering the {$cmdText}.");
			return;
		}
		$context->reply("Trying to add " . $newCommands->count() . " {$cmdText}...");
		$this->registerSlashCommands(
			function() use ($newCommands, $context, $cmdText): void {
				$context->reply(
					"Successfully registered " . $newCommands->count(). " new ".
					"Slash-{$cmdText}."
				);
			},
			function(string $errorMsg) use ($newCommands, $context, $cmdText): void {
				$this->db->table(self::DB_SLASH_TABLE)
					->whereIn("cmd", $newCommands->toArray())
					->delete();
				$context->reply(
					"Error registering " . $newCommands->count(). " new ".
					"Slash-{$cmdText}: {$errorMsg}"
				);
			}
		);
	}

	/** Remove one or more commands from the list of Discord slash-commands */
	#[NCA\HandlesCommand("discord slash-commands")]
	public function remDiscordSlashCommands(
		CmdContext $context,
		#[NCA\Str("slash")] string $action,
		PRemove $subAction,
		#[NCA\PWord] string ...$commands,
	): void {
		$cmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckAs("cmd", "string")
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
		$this->registerSlashCommands(
			function() use ($delCommands, $context, $cmdText): void {
				$context->reply(
					"Successfully removed " . $delCommands->count(). " ".
					"Slash-{$cmdText}."
				);
			},
			function(string $errorMsg) use ($delCommands, $context, $cmdText): void {
				$this->db->table(self::DB_SLASH_TABLE)
					->insert(
						$delCommands->map(function(string $cmd): array {
							return ["cmd" => $cmd];
						})->toArray()
					);
				$context->reply(
					"Error removing " . $delCommands->count(). " ".
					"Slash-{$cmdText}: {$errorMsg}"
				);
			}
		);
	}

	/** Pick commands to add to the list of Discord slash-commands */
	#[NCA\HandlesCommand("discord slash-commands")]
	public function pickDiscordSlashCommands(
		CmdContext $context,
		#[NCA\Str("slash")] string $action,
		#[NCA\Str("pick")] string $subAction,
	): void {
		/** @var string[] */
		$exposedCmds = $this->db->table(self::DB_SLASH_TABLE)
			->orderBy("cmd")
			->pluckAs("cmd", "string")
			->toArray();
		/** @var Collection<CmdCfg> */
		$cmds = new Collection($this->cmdManager->getAll(false));
		/** @var Collection<string> */
		$parts = $cmds
			->sortBy("module")
			->filter(function (CmdCfg $cmd) use ($exposedCmds): bool {
				return !in_array($cmd->cmd, $exposedCmds);
			})->groupBy("module")
			->map(function(Collection $cmds, string $module): string {
				$lines = $cmds->sortBy("cmd")->map(function(CmdCfg $cmd): string {
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
		if (!$this->gw->isMe($interaction->application_id)) {
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
			$channel = $this->gw->getChannel($interaction->channel_id);
			if (!isset($channel)) {
				return;
			}
			$context->source = Source::DISCORD_PRIV . "({$channel->name})";
			$cmdMap = $this->cmdManager->getPermsetMapForSource($context->source);
			if (!isset($cmdMap)) {
				$context->source = Source::DISCORD_PRIV . "({$channel->id})";
				$cmdMap = $this->cmdManager->getPermsetMapForSource($context->source);
			}
		} else {
			$context->source = Source::DISCORD_MSG . "({$discordUserId})";
			$cmdMap = $this->cmdManager->getPermsetMapForSource($context->source);
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
		$gw = $this->gw;
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
		if (isset($interaction->channel_id)
			&& $this->discordSlashCommands === self::SLASH_REGULAR
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
			$this->cmdManager->checkAndHandleCmd($context);
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
