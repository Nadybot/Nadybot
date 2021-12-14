<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Illuminate\Support\Collection;
use JsonException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

use Nadybot\Core\{
	ClassSpec,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	Event,
	EventManager,
	EventType,
	LoggerWrapper,
	MessageHub,
	Nadybot,
	SettingManager,
	Text,
	Util,
	Websocket,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PREFERENCES\Preferences,
	Modules\PROFILE\ProfileCommandReply,
	ParamClass\PNonNumber,
	ParamClass\PRemove,
	ParamClass\PWord,
	Registry,
};
use Nadybot\Core\ParamClass\PNonNumberWord;
use Nadybot\Modules\{
	GUILD_MODULE\GuildController,
	WEBSERVER_MODULE\ApiResponse,
	WEBSERVER_MODULE\HttpProtocolWrapper,
	WEBSERVER_MODULE\JsonImporter,
	WEBSERVER_MODULE\Request,
	WEBSERVER_MODULE\Response,
};
use Nadybot\Modules\RELAY_MODULE\RelayProtocol\RelayProtocolInterface;

/**
 * @author Tyrence
 * @author Nadyita
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "relay",
		accessLevel: "mod",
		description: "Setup and modify relays between bots",
		help: "relay.txt"
	),
	NCA\DefineCommand(
		command: "sync",
		accessLevel: "member",
		description: "Force syncing of next command if relay sync exists",
		help: "sync.txt"
	),
	NCA\ProvidesEvent("routable(message)")
]
class RelayController {
	public const DB_TABLE = 'relay_<myname>';
	public const DB_TABLE_LAYER = 'relay_layer_<myname>';
	public const DB_TABLE_ARGUMENT = 'relay_layer_argument_<myname>';
	public const DB_TABLE_EVENT = 'relay_event_<myname>';

	/** @var array<string,ClassSpec> */
	protected array $relayProtocols = [];

	/** @var array<string,ClassSpec> */
	protected array $transports = [];

	/** @var array<string,ClassSpec> */
	protected array $stackElements = [];

	/** @var array<string,Relay> */
	public array $relays = [];

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public QuickRelayController $quickRelayController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public Preferences $preferences;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public GuildController $guildController;

	#[NCA\Inject]
	public Websocket $websocket;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Event(
		name: "connect",
		description: "Load relays from database"
	)]
	public function loadRelays(): void {
		$relays = $this->getRelays();
		foreach ($relays as $relayConf) {
			try {
				$relay = $this->createRelayFromDB($relayConf);
				$this->addRelay($relay);
				$relay->init(function() use ($relay) {
					$this->logger->notice("Relay " . $relay->getName() . " initialized");
				});
			} catch (Exception $e) {
				$this->logger->error($e->getMessage(), ["exception" => $e]);
			}
		}
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->settingManager->add(
			$this->moduleName,
			'relay_guild_abbreviation',
			'Abbreviation to use for org name',
			'edit',
			'text',
			'none',
			'none'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_queue_size',
			'How many messages to queue when relay is offline',
			'edit',
			'number',
			'10',
			'10;20;50'
		);
		$this->loadStackComponents();
		$this->settingManager->registerChangeListener("relay_queue_size", [$this, "adaptQueueSize"]);
	}

	public function adaptQueueSize(string $setting, string $old, string $new): void {
		if ($new < 0) {
			throw new Exception("The queue length cannot be negative.");
		}
		foreach ($this->relays as $relay) {
			$relay->setMessageQueueSize((int)$new);
		}
	}

	public function loadStackComponents(): void {
		$types = [
			"RelayProtocol" => [
				NCA\RelayProtocol::class,
				[$this, "registerRelayProtocol"],
			],
			"Layer" => [
				NCA\RelayStackMember::class,
				[$this, "registerStackElement"],
			],
			"Transport" => [
				NCA\RelayTransport::class,
				[$this, "registerTransport"],
			]
		];
		foreach ($types as $dir => $data) {
			$files = glob(__DIR__ . "/{$dir}/*.php");
			foreach ($files as $file) {
				require_once $file;
				$className = basename($file, ".php");
				$fullClass = __NAMESPACE__ . "\\{$dir}\\{$className}";
				$spec = $this->util->getClassSpecFromClass($fullClass, $data[0]);
				if (isset($spec)) {
					$data[1]($spec);
				}
			}
		}
	}

	public function registerRelayProtocol(ClassSpec $proto): bool {
		$this->relayProtocols[strtolower($proto->name)] = $proto;
		return true;
	}

	public function registerTransport(ClassSpec $proto): bool {
		$this->transports[strtolower($proto->name)] = $proto;
		return true;
	}

	public function registerStackElement(ClassSpec $proto): bool {
		$this->stackElements[strtolower($proto->name)] = $proto;
		return true;
	}

	public function getGuildAbbreviation(): string {
		$abbr = $this->settingManager->getString('relay_guild_abbreviation') ?? 'none';
		if ($abbr !== 'none') {
			return $abbr;
		} else {
			return $this->chatBot->vars["my_guild"];
		}
	}

	public function getTransportSpec(string $name): ?ClassSpec {
		$spec = $this->transports[strtolower($name)] ?? null;
		if (isset($spec)) {
			$spec = clone $spec;
		}
		return $spec;
	}

	public function getStackElementSpec(string $name): ?ClassSpec {
		$spec = $this->stackElements[strtolower($name)] ?? null;
		if (isset($spec)) {
			$spec = clone $spec;
		}
		return $spec;
	}

	/**
	 * @param array<string,ClassSpec> $specs
	 * @return string[]
	 */
	protected function renderClassSpecOverview(array $specs, string $name, string $subCommand): array {
		$count = count($specs);
		if (!$count) {
			return ["No {$name}s available."];
		}
		$blobs = [];
		foreach ($specs as $spec) {
			$description = $spec->description ?? "Someone forgot to add a description";
			$entry = "<header2>{$spec->name}<end>\n".
				"<tab>".
				join("\n<tab>", explode("\n", trim($description)));
			if (count($spec->params)) {
				$entry .= "\n<tab>[" . $this->text->makeChatcmd("details", "/tell <myname> relay list {$subCommand} {$spec->name}") . "]";
			}
			$blobs []= $entry;
		}
		$blob = join("\n\n", $blobs);
		return (array)$this->text->makeBlob("Available {$name}s ({$count})", $blob);
	}

	/**
	 * @param array<string,ClassSpec> $specs
	 * @return string[]
	 */
	protected function renderClassSpecDetails(array $specs, string $key, string $name): array {
		$spec = $specs[$key] ?? null;
		if (!isset($spec)) {
			return ["No {$name} <highlight>{$key}<end> found."];
		}
		try {
			$refClass = new ReflectionClass($spec->class);
		} catch (ReflectionException $e) {
			return ["<highlight>{$spec->name}<end> cannot be initialized."];
		}
		try {
			$refConstr = $refClass->getMethod("__construct");
			$refParams = $refConstr->getParameters();
		} catch (ReflectionException $e) {
			$refParams = [];
		}
		$description = $spec->description ?? "Someone forgot to add a description";
		$blob = "<header2>Description<end>\n".
			"<tab>" . join("\n<tab>", explode("\n", trim($description))).
			"\n";
		if (count($spec->params)) {
			$blob .= "\n<header2>Parameters<end>\n";
			$parNum = 0;
			foreach ($spec->params as $param) {
				$type = ($param->type === $param::TYPE_SECRET) ? $param::TYPE_STRING : $param->type;
				$blob .= "<tab><green>{$type}<end> <highlight>{$param->name}<end>";
				if (!$param->required) {
					if (isset($refParams[$parNum]) && $refParams[$parNum]->isDefaultValueAvailable()) {
						try {
							$blob .= " (optional, default=".
								json_encode(
									$refParams[$parNum]->getDefaultValue(),
									JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE
								) . ")";
						} catch (JsonException $e) {
							$blob .= " (optional)";
						}
					} else {
						$blob .= " (optional)";
					}
				}
				$parNum++;
				$blob .= "\n<tab><i>".
					join("</i>\n<tab><i>", explode("\n", $param->description ?? "No description")).
					"</i>\n\n";
			}
		}
		return (array)$this->text->makeBlob(
			"Detailed description for {$spec->name}",
			$blob
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayListProtocolsCommand(
		CmdContext $context,
		#[NCA\Str("list")]
		string $action,
		#[NCA\Regexp("protocols?")]
		string $subAction
	): void {
		$context->reply(
			$this->renderClassSpecOverview(
				$this->relayProtocols,
				"relay protocol",
				"protocol"
			)
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayListProtocolDetailCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action,
		#[NCA\Str("protocol")] string $subAction,
		string $protocol
	): void {
		$context->reply(
			$this->renderClassSpecDetails(
				$this->relayProtocols,
				$protocol,
				"relay protocol",
			)
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayListTransportsCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action,
		#[NCA\Regexp("transports?")] string $subAction
	): void {
		$context->reply(
			$this->renderClassSpecOverview(
				$this->transports,
				"relay transport",
				"transport"
			)
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayListTransportDetailCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action,
		#[NCA\Str("transport")] string $subAction,
		string $transport
	): void {
		$context->reply(
			$this->renderClassSpecDetails(
				$this->transports,
				$transport,
				"relay transport",
			)
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayListStacksCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action,
		#[NCA\Regexp("layers?")] string $subAction
	): void {
		$context->reply(
			$this->renderClassSpecOverview(
				$this->stackElements,
				"relay layer",
				"layer"
			)
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayListStackDetailCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action,
		#[NCA\Str("layer")] string $subAction,
		string $layer
	): void {
		$context->reply(
			$this->renderClassSpecDetails(
				$this->stackElements,
				$layer,
				"relay layer"
			)
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PWord $name,
		string $spec
	): void {
		$name = $name();
		if (strlen($name) > 100) {
			$context->reply("The name of the relay must be 100 characters max.");
			return;
		}
		$relayConf = new RelayConfig();
		$relayConf->name = $name;
		$parser = new RelayLayerExpressionParser();
		try {
			$relayConf->layers = $parser->parse($spec);
		} catch (LayerParserException $e) {
			$context->reply($e->getMessage());
			return;
		}
		try {
			$relay = $this->createRelay($relayConf);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$layers = [];
		foreach ($relayConf->layers as $layer) {
			$layers []= $layer->toString();
		}
		$blob = $this->quickRelayController->getRouteInformation(
			$name,
			isset($layer) && in_array($layer->layer, ["tyrbot", "nadynative"])
		);
		$msg = "Relay <highlight>{$name}<end> added.";
		if (!$this->messageHub->hasRouteFor($relay->getChannelName()) && !($context instanceof ProfileCommandReply)) {
			$help = (array)$this->text->makeBlob("setup your routing", $blob);
			$msg .= " Make sure to {$help[0]}, otherwise no messages will be exchanged.";
		}
		if ($relay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$msg .= " This protocol supports relaying certain events. Use ".
				"<highlight><symbol>relay config {$relayConf->name}<end> to configure which ones.";
		}
		$context->reply($msg);
	}

	public function createRelay(RelayConfig $relayConf): Relay {
		if ($this->getRelayByName($relayConf->name)) {
			throw new Exception("The relay <highlight>{$relayConf->name}<end> already exists.");
		}
		$transactionActive = false;
		try {
			$this->db->beginTransaction();
		} catch (Exception $e) {
			$transactionActive = true;
		}
		try {
			$relayConf->id = $this->db->insert(static::DB_TABLE, $relayConf);
			foreach ($relayConf->layers as $layer) {
				$layer->relay_id = $relayConf->id;
				$layer->id = $this->db->insert(static::DB_TABLE_LAYER, $layer);
				foreach ($layer->arguments as $argument) {
					$argument->layer_id = $layer->id;
					$argument->id = $this->db->insert(static::DB_TABLE_ARGUMENT, $argument);
				}
			}
			foreach ($relayConf->events as $event) {
				$event->relay_id = $relayConf->id;
				$event->id = $this->db->insert(static::DB_TABLE_EVENT, $event);
			}
		} catch (Throwable $e) {
			if ($transactionActive) {
				throw $e;
			}
			$this->db->rollback();
			throw new Exception("Error saving the relay: " . $e->getMessage());
		}
		try {
			$relay = $this->createRelayFromDB($relayConf);
		} catch (Exception $e) {
			if (!$transactionActive) {
				$this->db->rollback();
			}
			throw $e;
		}
		if (!$this->addRelay($relay)) {
			if (!$transactionActive) {
				$this->db->rollback();
			}
			throw new Exception("A relay with that name is already registered");
		}
		if (!$transactionActive) {
			$this->db->commit();
		}
		$relay->init(function() use ($relay) {
			$this->logger->notice("Relay " . $relay->getName() . " initialized");
		});
		return $relay;
	}

	public function deleteRelay(RelayConfig $relay): bool {
		/** @var int[] List of modifier-ids for the route */
		$layers = array_column($relay->layers, "id");
		$transactionActive = false;
		try {
			$this->db->beginTransaction();
		} catch (Exception $e) {
			$transactionActive = true;
		}
		try {
			if (count($layers)) {
				$this->db->table(static::DB_TABLE_ARGUMENT)
					->whereIn("layer_id", $layers)
					->delete();
				$this->db->table(static::DB_TABLE_LAYER)
					->where("relay_id", $relay->id)
					->delete();
			}
			$this->db->table(static::DB_TABLE_EVENT)
				->where("relay_id", $relay->id)
				->delete();
			$this->db->table(static::DB_TABLE)
				->delete($relay->id);
		} catch (Throwable $e) {
			if (!$transactionActive) {
				$this->db->rollback();
			}
			throw $e;
		}
		if (!$transactionActive) {
			$this->db->commit();
		}
		$liveRelay = $this->relays[$relay->name] ?? null;
		unset($this->relays[$relay->name]);
		if (!isset($liveRelay)) {
			return false;
		}
		$liveRelay->deinit(function(Relay $relay) {
			$this->logger->notice("Relay " . $relay->getName() . " destroyed");
			unset($relay);
		});
		return true;
	}

	/** Delete all relays and return how many were deleted */
	public function deleteAllRelays(): int {
		$relays = $this->getRelays();
		foreach ($relays as $relay) {
			$this->deleteRelay($relay);
		}
		$this->db->table(static::DB_TABLE_ARGUMENT)->truncate();
		$this->db->table(static::DB_TABLE_LAYER)->truncate();
		$this->db->table(static::DB_TABLE_EVENT)->truncate();
		$this->db->table(static::DB_TABLE)->truncate();
		return count($relays);
	}

	/**
	 * Get a list of commands to create all current relays
	 * @return string[]
	 */
	public function getRelayDump(): array {
		$relays = $this->getRelays();
		return array_map(function (RelayConfig $relay): string {
			$msg = "!relay add {$relay->name}";
			foreach ($relay->layers as $layer) {
				$msg .= " " . $layer->toString();
			}
			if (!empty($relay->events)) {
				$events = [];
				foreach ($relay->events as $event) {
					$events []= $event->toString();
				}
				$msg .= "\n!relay config {$relay->name} eventset ".
					join(" ", $events);
			}
			return $msg;
		}, $relays);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayDescribeIdCommand(
		CmdContext $context,
		#[NCA\Str("describe")] string $action,
		int $id
	): void {
		$this->relayDescribeCommand($context, $id, null);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayDescribeNameCommand(
		CmdContext $context,
		#[NCA\Str("describe")] string $action,
		PNonNumber $name
	): void {
		$this->relayDescribeCommand($context, null, $name());
	}

	public function relayDescribeCommand(CmdContext $context, ?int $id, ?string $name): void {
		if (!$context->isDM()) {
			$context->reply(
				"Because the relay stack might contain passwords, ".
				"this command works only in tells."
			);
			return;
		}
		$relay = isset($id)
			? $this->getRelay($id)
			: $this->getRelayByName($name??"");
		/** @var ?RelayConfig $relay */
		if (!isset($relay)) {
			$context->reply(
				"Relay <highlight>".
				(isset($id) ? "#{$id}" : ($name??"unknown")).
				"<end> not found."
			);
			return;
		}
		$msg = "<symbol>relay add {$relay->name}";
		foreach ($relay->layers as $layer) {
			$msg .= " " . $layer->toString();
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayListCommand(CmdContext $context, #[NCA\Str("list")] ?string $action): void {
		$relays = $this->getRelays();
		if (empty($relays)) {
			$context->reply("There are no relays defined.");
			return;
		}
		$blobs = [];
		foreach ($relays as $relay) {
			$blob = "<header2>{$relay->name}<end>\n";
			if (isset($this->transports[$relay->layers[0]->layer])) {
				$secrets = $this->transports[$relay->layers[0]->layer]->getSecrets();
				$blob .= "<tab>Transport: <highlight>" . $relay->layers[0]->toString("transport", $secrets) . "<end>\n";
			} else {
				$blob .= "<tab>Transport: <highlight>{$relay->layers[0]->layer}(<red>error<end>)<end>\n";
			}
			for ($i = 1; $i < count($relay->layers)-1; $i++) {
				if (isset($this->stackElements[$relay->layers[$i]->layer])) {
					$secrets = $this->stackElements[$relay->layers[$i]->layer]->getSecrets();
					$blob .= "<tab>Layer: <highlight>" . $relay->layers[$i]->toString("layer", $secrets) . "<end>\n";
				} else {
					$blob .= "<tab>Layer: <highlight>{$relay->layers[$i]->layer}(<red>error<end>)<end>\n";
				}
			}
			$layerName = $relay->layers[count($relay->layers)-1]->layer;
			if (isset($this->relayProtocols[$layerName])) {
				$secrets = $this->relayProtocols[$relay->layers[count($relay->layers)-1]->layer]->getSecrets();
				$blob .= "<tab>Protocol: <highlight>" . $relay->layers[count($relay->layers)-1]->toString("protocol", $secrets) . "<end>\n";
			} else {
				$blob .= "<tab>Protocol: <highlight>{$layerName}(<red>error<end>)<end>\n";
			}
			$live = $this->relays[$relay->name] ?? null;
			if (isset($live)) {
				$blob .= "<tab>Status: " . $live->getStatus()->toString();
			} else {
				$blob .= "<tab>Status: <red>error<end>";
			}
			$delLink = $this->text->makeChatcmd(
				"delete",
				"/tell <myname> relay rem {$relay->id}"
			);
			$desclLink = $this->text->makeChatcmd(
				"describe",
				"/tell <myname> relay describe {$relay->id}"
			);
			$blobs []= $blob . " [{$delLink}] [{$desclLink}]";
		}
		$msg = $this->text->makeBlob(
			"Relays (" . count($relays) . ")",
			join("\n\n", $blobs)
		);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayRemIdCommand(CmdContext $context, PRemove $action, int $id): void {
		$this->relayRemCommand($context, $id, null);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayRemNameCommand(CmdContext $context, PRemove $action, PNonNumber $name): void {
		$this->relayRemCommand($context, null, $name());
	}

	public function relayRemCommand(CmdContext $context, ?int $id, ?string $name): void {
		$relay = isset($id)
			? $this->getRelay($id)
			: $this->getRelayByName($name??"");
		if (!isset($relay)) {
			$context->reply(
				"Relay <highlight>".
				(isset($id) ? "#{$id}" : ($name??"unknown")).
				"<end> not found."
			);
			return;
		}
		try {
			$this->deleteRelay($relay);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply(
			"Relay #{$relay->id} (<highlight>{$relay->name}<end>) deleted."
		);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayRemAllCommand(CmdContext $context, #[NCA\Regexp("remall|delall")] string $action): void {
		$numDeleted = $this->deleteAllRelays();
		$context->reply("<highlight>{$numDeleted}<end> relays deleted.");
	}

	#[NCA\HandlesCommand("relay")]
	public function relayConfigIdCommand(CmdContext $context, #[NCA\Str("config")] string $action, int $id): void {
		$this->relayConfigCommand($context, $id, null);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayConfigNameCommand(CmdContext $context, #[NCA\Str("config")] string $action, PNonNumberWord $name): void {
		$this->relayConfigCommand($context, null, $name());
	}

	public function relayConfigCommand(CmdContext $context, ?int $id, ?string $name): void {
		$relay = isset($id)
			? $this->getRelay($id)
			: $this->getRelayByName($name??"");
		if (!isset($relay)) {
			$context->reply(
				"Relay <highlight>".
				(isset($id) ? "#{$id}" : ($name??"unknown")).
				"<end> not found."
			);
			return;
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$context->reply("This relay has nothing to configure.");
			return;
		}
		$events = $this->getRegisteredSyncEvents();
		$blob = "This relay protocol supports sending events between bots on the same relay.\n".
			"For this to work, the bot sending the event must allow outgoing events of\n".
			"that event type and the receiving bot(s) must allow incoming events of that\n".
			"very type.\n\n".
			"If bot 'Alice' allows outgoing sync(cd) and 'Bobby' allows incoming sync(cd),\n".
			"then every time someone on 'Alice' starts a countdown, the same countdown will\n".
			"be started on Bobby in sync.\n\n".
			"If you only want to send these events selectively, you can prefix your commands\n".
			"with 'sync' instead of enabling that outgoing sync-event, e.g. '<symbol>sync cd KILL!'.\n\n";
		$blob .= "<header2>Syncable events<end>";
		foreach ($events as $event) {
			$eConf = $relay->getEvent($event->name) ?? new RelayEvent();
			$line = "\n<tab><highlight>{$event->name}<end>:";
			foreach (["incoming", "outgoing"] as $type) {
				if ($eConf->{$type}) {
					$line .= " <green>" . ucfirst($type) . "<end> [".
						$this->text->makeChatcmd(
							"disable",
							"/tell <myname> relay config {$relay->name} eventmod {$event->name} disable {$type}"
						) . "]";
				} else {
					$line .= " <red>" . ucfirst($type) . "<end> [".
						$this->text->makeChatcmd(
							"enable",
							"/tell <myname> relay config {$relay->name} eventmod {$event->name} enable {$type}"
						) . "]";
				}
			}
			$blob .= $line;
			if (isset($event->description)) {
				$blob .= "\n<tab><tab><i>{$event->description}</i>";
			}
			$blob .= "\n";
		}
		$msg = $this->text->makeBlob("Relay configuration for {$relay->name}", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("relay")]
	public function relayConfigEventmodCommand(
		CmdContext $context,
		#[NCA\Str("config")] string $action,
		PWord $name,
		#[NCA\Str("eventmod")] string $subAction,
		PWord $event,
		bool $enable,
		#[NCA\Regexp("incoming|outgoing")] string $direction
	): void {
		$name = $name();
		$relay = $this->getRelayByName($name);
		if (!isset($relay)) {
			$context->reply("Relay <highlight>{$name}<end> not found.");
			return;
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$context->reply(
				"The relay <highlight>{$relay->name}<end> uses a protocol which ".
				"does not support syncing events."
			);
			return;
		}
		$statusMsg = $enable ? "<green>enabled<end>" : "<red>disabled<end>";
		if ($this->changeRelayEventStatus($relay, $event(), $direction, $enable)) {
			$context->reply(
				"Successfully {$statusMsg} {$direction} events of type <highlight>".
				$event() . "<end> for relay <highlight>{$relay->name}<end>."
			);
			return;
		}
		$context->reply(
			ucfirst($direction) . " events of type <highlight>" . $event() . "<end> ".
			"were already {$statusMsg} for relay <highlight>{$relay->name}<end>."
		);
	}

	protected function changeRelayEventStatus(RelayConfig $relay, string $eventName, string $direction, bool $enable): bool {
		$event = $relay->getEvent($eventName);
		if (!isset($event)) {
			if ($enable === false) {
				return false;
			}
			$event = new RelayEvent();
			$event->event = $eventName;
			$event->relay_id = $relay->id;
		}
		if ($event->{$direction} === $enable) {
			return false;
		}
		$event->{$direction} = $enable;
		if (isset($event->id)) {
			if ($event->incoming === false && $event->outgoing === false) {
				$this->db->table(static::DB_TABLE_EVENT)->delete($event->id);
				$relay->deleteEvent($eventName);
			} else {
				$this->db->update(static::DB_TABLE_EVENT, "id", $event);
			}
		} else {
			$event->id = $this->db->insert(static::DB_TABLE_EVENT, $event, "id");
			$relay->addEvent($event);
		}
		$this->relays[$relay->name]->setEvents($relay->events);
		return true;
	}

	#[NCA\HandlesCommand("relay")]
	public function relayConfigEventsetCommand(
		CmdContext $context,
		#[NCA\Str("config")] string $action,
		PWord $name,
		#[NCA\Str("eventset")] string $subAction,
		#[NCA\Regexp("[a-z()_-]+\s+(?:IO|O|I)")] ?string ...$events
	): void {
		$name = $name();
		$relay = $this->getRelayByName($name);
		if (!isset($relay)) {
			$context->reply("Relay <highlight>{$name}<end> not found.");
			return;
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$context->reply(
				"The relay <highlight>{$relay->name}<end> uses a protocol which ".
				"does not support syncing events."
			);
			return;
		}
		$eventConfigs = [];
		foreach ($events as $eventConfig) {
			[$eventName, $dir] = preg_split("/\s+/", $eventConfig??"");
			$eventConfigs[$eventName] = $dir;
		}
		$this->db->table(static::DB_TABLE_EVENT)
			->where("relay_id", $relay->id)
			->delete();
		$relay->events = [];
		foreach ($eventConfigs as $eventName => $dir) {
			$event = new RelayEvent();
			$event->relay_id = $relay->id;
			$event->event = $eventName;
			$event->incoming = stripos($dir, "I") !== false;
			$event->outgoing = stripos($dir, "O") !== false;
			$event->id = $this->db->insert(static::DB_TABLE_EVENT, $event, "id");
			$relay->addEvent($event);
		}
		$this->relays[$relay->name]->setEvents($relay->events);
		$context->reply("Relay events set for <highlight>{$relay->name}<end>.");
	}

	#[NCA\HandlesCommand("sync")]
	public function syncCommand(CmdContext $context, string $command): void {
		$context->message = $command;
		$context->forceSync = true;
		$this->commandManager->processCmd($context);
	}

	/**
	 * Get a list of all registered sync events as array with names
	 * @return EventType[]
	 */
	protected function getRegisteredSyncEvents(): array {
		return array_values(
			array_filter(
				$this->eventManager->getEventTypes(),
				function (EventType $event): bool {
					return fnmatch("sync(*)", $event->name, FNM_CASEFOLD);
				}
			)
		);
	}

	/**
	 * Read all defined relays from the database
	 * @return RelayConfig[]
	 */
	public function getRelays(): array {
		$arguments = $this->db->table(static::DB_TABLE_ARGUMENT)
			->orderBy("id")
			->asObj(RelayLayerArgument::class)
			->groupBy("layer_id");
		$layers = $this->db->table(static::DB_TABLE_LAYER)
			->orderBy("id")
			->asObj(RelayLayer::class)
			->each(function (RelayLayer $layer) use ($arguments): void {
				$layer->arguments = $arguments->get($layer->id, new Collection())->toArray();
			})
			->groupBy("relay_id");
		$events = $this->db->table(static::DB_TABLE_EVENT)
			->orderBy("id")
			->asObj(RelayEvent::class)
			->groupBy("relay_id");
		$relays = $this->db->table(static::DB_TABLE)
			->orderBy("id")
			->asObj(RelayConfig::class)
			->each(function(RelayConfig $relay) use ($layers, $events): void {
				$relay->layers = $layers->get($relay->id, new Collection())->toArray();
				$relay->events = $events->get($relay->id, new Collection())
					->toArray();
			})
			->toArray();
		return $relays;
	}

	/** Read a relay by its ID */
	public function getRelay(int $id): ?RelayConfig {
		/** @var RelayConfig|null */
		$relay = $this->db->table(static::DB_TABLE)
			->where("id", $id)
			->limit(1)
			->asObj(RelayConfig::class)
			->first();
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	/** Read a relay by its name */
	public function getRelayByName(string $name): ?RelayConfig {
		/** @var RelayConfig|null */
		$relay = $this->db->table(static::DB_TABLE)
			->where("name", $name)
			->limit(1)
			->asObj(RelayConfig::class)
			->first();
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	/** Add layers and args to a relay from the DB */
	protected function completeRelay(RelayConfig $relay): void {
		$relay->layers = $this->db->table(static::DB_TABLE_LAYER)
		->where("relay_id", $relay->id)
		->orderBy("id")
		->asObj(RelayLayer::class)
		->toArray();
		foreach ($relay->layers as $layer) {
			$layer->arguments = $this->db->table(static::DB_TABLE_ARGUMENT)
			->where("layer_id", $layer->id)
			->orderBy("id")
			->asObj(RelayLayerArgument::class)
			->toArray();
		}
		$relay->events = $this->db->table(static::DB_TABLE_EVENT)
			->where("relay_id", $relay->id)
			->orderBy("id")
			->asObj(RelayEvent::class)
			->toArray();
	}

	public function addRelay(Relay $relay): bool {
		if (isset($this->relays[$relay->getName()])) {
			return false;
		}
		$relay->setMessageQueueSize($this->settingManager->getInt("relay_queue_size")??10);
		$this->relays[$relay->getName()] = $relay;
		return true;
	}

	protected function createRelayFromDB(RelayConfig $conf): Relay {
		$relay = new Relay($conf->name);
		Registry::injectDependencies($relay);
		if (count($conf->layers) < 2) {
			throw new Exception(
				"Every relay must have at least 1 transport and 1 protocol."
			);
		}
		// The order is assumed to be transport --- protocol
		// If it's the other way around, let's reverse it
		if (
			!isset($this->transports[$conf->layers[0]->layer])
			&& isset($this->relayProtocols[$conf->layers[0]->layer])
		) {
			$conf->layers = array_reverse($conf->layers);
		}

		$stack = [];
		$transport = array_shift($conf->layers);
		$spec = $this->transports[strtolower($transport->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$transport->layer}<end> is not a ".
				"known transport for relaying. Perhaps the order was wrong?"
			);
		}
		$transportLayer = $this->getRelayLayer(
			$transport->layer,
			$transport->getKVArguments(),
			$spec
		);

		for ($i = 0; $i < count($conf->layers)-1; $i++) {
			$layerName = strtolower($conf->layers[$i]->layer);
			$spec = $this->stackElements[$layerName] ?? null;
			if (!isset($spec)) {
				throw new Exception(
					"<highlight>{$layerName}<end> is not a ".
					"known layer for relaying. Perhaps the order was wrong?"
				);
			}
			$stack []= $this->getRelayLayer(
				$layerName,
				$conf->layers[$i]->getKVArguments(),
				$spec
			);
		}

		$proto = array_pop($conf->layers);
		$spec = $this->relayProtocols[strtolower($proto->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$proto->layer}<end> is not a ".
				"known relay protocol. Perhaps the order was wrong?"
			);
		}
		$protocolLayer = $this->getRelayLayer(
			$proto->layer,
			$proto->getKVArguments(),
			$spec
		);
		$relay->setStack($transportLayer, $protocolLayer, ...$stack);
		$relay->setEvents($conf->events);
		return $relay;
	}

	/**
	 * Get a fully configured relay layer or null if not possible
	 * @param string $name Name of the layer
	 * @param array<string,string> $params The parameters of the layer
	 */
	public function getRelayLayer(string $name, array $params, ClassSpec $spec): object {
		$name = strtolower($name);
		$arguments = [];
		$paramPos = 0;
		foreach ($spec->params as $parameter) {
			$value = $params[$parameter->name] ?? null;
			if (isset($value)) {
				switch ($parameter->type) {
					case $parameter::TYPE_BOOL:
						if (!in_array($value, ["true", "false"])) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be 'true' or 'false', ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= $value === "true";
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_INT:
						if (!preg_match("/^[+-]?\d+/", $value)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be a number, ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= (int)$value;
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_STRING_ARRAY:
						$value = array_map(fn($x) => (string)$x, (array)$value);
						$arguments []= $value;
						unset($params[$parameter->name]);
						break;
					default:
						$arguments []= (string)$value;
						unset($params[$parameter->name]);
				}
			} elseif ($parameter->required) {
				throw new Exception(
					"Missing required argument <highlight>{$parameter->name}<end> ".
					"to <highlight>{$name}<end>."
				);
			} else {
				$ref = new ReflectionMethod($spec->class, "__construct");
				$conParams = $ref->getParameters();
				if (!isset($conParams[$paramPos])) {
					continue;
				}
				if ($conParams[$paramPos]->isOptional()) {
					$arguments []= $conParams[$paramPos]->getDefaultValue();
				}
			}
			$paramPos++;
		}
		if (!empty($params)) {
			throw new Exception(
				"Unknown parameter" . (count($params) > 1 ? "s" : "").
				" <highlight>".
				(new Collection(array_keys($params)))
					->join("<end>, <highlight>", "<end> and <highlight>").
				"<end> to <highlight>{$name}<end>."
			);
		}
		$class = $spec->class;
		try {
			$result = new $class(...$arguments);
			Registry::injectDependencies($result);
			return $result;
		} catch (Throwable $e) {
			throw new Exception("There was an error setting up the {$name} layer: " . $e->getMessage());
		}
	}

	/**
	 * List all relay transports
	 */
	#[
		NCA\Api("/relay-component/transport"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "ClassSpec[]", desc: "The available relay transport layers")
	]
	public function apiGetTransportsEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->transports));
	}

	/**
	 * List all relay layers
	 */
	#[
		NCA\Api("/relay-component/layer"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "ClassSpec[]", desc: "The available generic relay layers")
	]
	public function apiGetLayersEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->stackElements));
	}

	/**
	 * List all relay protocols
	 */
	#[
		NCA\Api("/relay-component/protocol"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "ClassSpec[]", desc: "The available relay protocols")
	]
	public function apiGetProtocolsEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->relayProtocols));
	}

	/**
	 * List all relays
	 */
	#[
		NCA\Api("/relay"),
		NCA\GET,
		NCA\AccessLevelFrom("relay"),
		NCA\ApiResult(code: 200, class: "RelayConfig[]", desc: "The configured relays")
	]
	public function apiGetRelaysEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->getRelays()));
	}

	/**
	 * Get a single relay
	 */
	#[
		NCA\Api("/relay/%s"),
		NCA\GET,
		NCA\AccessLevelFrom("relay"),
		NCA\ApiResult(code: 200, class: "RelayConfig", desc: "The configured relay"),
		NCA\ApiResult(code: 404, desc: "Relay not found")
	]
	public function apiGetRelayByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($relay);
	}

	/**
	 * Get a single relay's event config
	 */
	#[
		NCA\Api("/relay/%s/events"),
		NCA\GET,
		NCA\AccessLevelFrom("relay"),
		NCA\ApiResult(code: 200, class: "RelayEvent[]", desc: "The configured relay events"),
		NCA\ApiResult(code: 404, desc: "Relay not found")
	]
	public function apiGetRelayEventsByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($relay->events);
	}

	/**
	 * Get a single relay's event config
	 */
	#[
		NCA\Api("/relay/%s/events"),
		NCA\PUT,
		NCA\AccessLevelFrom("relay"),
		NCA\RequestBody(class: "RelayEvent[]", desc: "The event configuration", required: true),
		NCA\ApiResult(code: 204, desc: "The event configuration was set"),
		NCA\ApiResult(code: 404, desc: "Relay not found")
	]
	public function apiPutRelayEventsByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(Response::NOT_FOUND);
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			return new Response(Response::NOT_FOUND);
		}
		$events = $request->decodedBody;
		if (!is_array($events)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		try {
			foreach ($events as &$event) {
				/** @var RelayEvent */
				$event = JsonImporter::convert(RelayEvent::class, $event);
			}
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$this->db->beginTransaction();
		$oldEvents = $relay->events;
		try {
			$this->db->table(static::DB_TABLE_EVENT)
				->where("relay_id", $relay->id)
				->delete();
			$relay->events = [];
			foreach ($events as $event) {
				$event->relay_id = $relay->id;
				$event->id = $this->db->insert(static::DB_TABLE_EVENT, $event, "id");
				$relay->addEvent($event);
			}
			$this->relays[$relay->name]->setEvents($relay->events);
		} catch (Throwable $e) {
			$this->db->rollback();
			$relay->events = $oldEvents;
			return new Response(Response::INTERNAL_SERVER_ERROR);
		}
		$this->db->commit();
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Get a single relay's event config
	 */
	#[
		NCA\Api("/relay/%s/events"),
		NCA\PATCH,
		NCA\AccessLevelFrom("relay"),
		NCA\RequestBody(class: "RelayEvent", desc: "The changed event configuration for one event", required: true),
		NCA\ApiResult(code: 204, desc: "The event configuration was set"),
		NCA\ApiResult(code: 404, desc: "Relay not found")
	]
	public function apiPatchRelayEventsByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(Response::NOT_FOUND);
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			return new Response(Response::NOT_FOUND);
		}
		$event = $request->decodedBody;
		if (!is_object($event)) {
			return new Response(Response::UNPROCESSABLE_ENTITY, []);
		}
		try {
			/** @var RelayEvent */
			JsonImporter::convert(RelayEvent::class, $event);
			if (!isset($event->event)) {
				throw new Exception("event name not given");
			}
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY, [], $e->getMessage());
		}
		if (isset($event->incoming)) {
			$this->changeRelayEventStatus($relay, $event->event, "incoming", $event->incoming);
		}
		if (isset($event->outgoing)) {
			$this->changeRelayEventStatus($relay, $event->event, "outgoing", $event->outgoing);
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Delete a relay
	 */
	#[
		NCA\Api("/relay/%s"),
		NCA\DELETE,
		NCA\AccessLevelFrom("relay"),
		NCA\ApiResult(code: 204, desc: "The relay was deleted"),
		NCA\ApiResult(code: 404, desc: "Relay not found")
	]
	public function apiDelRelayByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(Response::NOT_FOUND);
		}
		try {
			$this->deleteRelay($relay);
		} catch (Exception $e) {
			return new Response(Response::INTERNAL_SERVER_ERROR, [], $e->getMessage());
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Get a relay's status
	 */
	#[
		NCA\Api("/relay/%s/status"),
		NCA\GET,
		NCA\AccessLevelFrom("relay"),
		NCA\ApiResult(code: 200, class: "RelayStatus", desc: "The status message of the relay"),
		NCA\ApiResult(code: 404, desc: "Relay not found")
	]
	public function apiGetRelayStatusByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		if (!isset($this->relays[$relay])) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($this->relays[$relay]->getStatus());
	}

	/**
	 * Create a new relay
	 */
	#[
		NCA\Api("/relay"),
		NCA\POST,
		NCA\AccessLevelFrom("relay"),
		NCA\ApiResult(code: 204, desc: "Relay created successfully")
	]
	public function apiCreateRelay(Request $request, HttpProtocolWrapper $server): Response {
		$relay = $request->decodedBody;
		if (!is_object($relay)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		try {
			/** @var RelayConfig */
			$relay = JsonImporter::convert(RelayConfig::class, $relay);
			foreach ($relay->layers as &$layer) {
				/** @var RelayLayer */
				$layer = JsonImporter::convert(RelayLayer::class, $layer);
				foreach ($layer->arguments as &$argument) {
					$argument = JsonImporter::convert(RelayLayerArgument::class, $argument);
				}
			}
			$relay->events ??= [];
			foreach ($relay->events as &$event) {
				/** @var RelayEvent */
				$event = JsonImporter::convert(RelayEvent::class, $event);
			}
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		try {
			$this->createRelay($relay);
		} catch (Exception $e) {
			return new Response(
				Response::INTERNAL_SERVER_ERROR,
				[],
				$this->text->formatMessage($e->getMessage())
			);
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * List all relay layers
	 */
	#[
		NCA\Api("/relay-component/event"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "EventType[]", desc: "The available non-routable relay events")
	]
	public function apiGetEventsEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->getRegisteredSyncEvents());
	}
}
