<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use function Safe\{json_decode, json_encode};

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Http,
	ClassSpec,
	CmdContext,
	Collection,
	CommandManager,
	Config\BotConfig,
	DB,
	EventManager,
	EventType,
	Events\ConnectEvent,
	Hydrator,
	MessageHub,
	ModuleInstance,
	Modules\PROFILE\ProfileCommandReply,
	ParamClass\PUuid,
	Registry,
	Safe,
	Text,
	Types\AccessLevel,
	Types\AccessLevelProvider,
	Types\ParamType,
	Util,
};
use Nadybot\Core\Attributes\Parameter\{NonNumberStr, NonNumberWord, Regexp, Remove, Str, WordStr};
use Nadybot\Core\Routing\{Character, RoutableMessage, Source};
use Nadybot\Modules\{
	RELAY_MODULE\RelayProtocol\RelayProtocolInterface,
	RELAY_MODULE\Transport\TransportInterface,
	WEBSERVER_MODULE\ApiResponse,
	WEBSERVER_MODULE\StatsController,
};
use Nadybot\Modules\WEBSERVER_MODULE\WebserverController;
use Psl\Type;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use ReflectionClass;

use ReflectionException;
use ReflectionMethod;
use Safe\Exceptions\JsonException;

use Throwable;

/**
 * @author Tyrence
 * @author Nadyita
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HasTests,
	NCA\DefineCommand(
		command: 'relay',
		accessLevel: AccessLevel::Mod,
		description: 'Setup and modify relays between bots',
	),
	NCA\DefineCommand(
		command: 'sync',
		accessLevel: AccessLevel::Member,
		description: 'Force syncing of next command if relay sync exists',
	),
]
class RelayController extends ModuleInstance implements AccessLevelProvider {
	private const NONE = 'none';

	/** @var array<string,Relay> */
	public array $relays = [];

	/** Abbreviation to use for org name */
	#[NCA\Setting\Text(options: ['none'])]
	public string $relayGuildAbbreviation = self::NONE;

	/** How many messages to queue when relay is offline */
	#[NCA\Setting\Number(options: ['10', '20', '50'])]
	public int $relayQueueSize = 10;

	/** @var array<string,ClassSpec> */
	protected array $relayProtocols = [];

	/** @var array<string,ClassSpec> */
	protected array $transports = [];

	/** @var array<string,ClassSpec> */
	protected array $stackElements = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private QuickRelayController $quickRelayController;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private StatsController $statsController;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	/** Load relays from database */
	#[NCA\HandlesEvent]
	public function loadRelays(ConnectEvent $event): void {
		$relays = $this->getRelays();
		foreach ($relays as $relayConf) {
			try {
				$relay = $this->createRelayFromDB($relayConf);
				$this->addRelay($relay);
				$relay->init(function () use ($relay): void {
					$this->logger->notice('Relay {relay_name} initialized', [
						'relay_name' => $relay->getName(),
					]);
				});
			} catch (Exception $e) {
				$this->logger->error('{error}', [
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->loadStackComponents();
		$relayStats = new OnlineRelayStats();
		Registry::injectDependencies($relayStats);
		$this->statsController->registerProvider($relayStats, 'online');
	}

	#[NCA\SettingChangeHandler('relay_queue_size')]
	public function adaptQueueSize(string $setting, string $old, string $new): void {
		if ($new < 0) {
			throw new Exception('The queue length cannot be negative.');
		}
		foreach ($this->relays as $relay) {
			$relay->setMessageQueueSize((int)$new);
		}
	}

	public function getSingleAccessLevel(string $sender): ?AccessLevel {
		foreach ($this->relays as $relayName => $relay) {
			if ($relay->treatOnlineAsGuest === false) {
				continue;
			}
			foreach ($relay->getOnlineList() as $where => $onlineStats) {
				foreach ($onlineStats as $charName => $player) {
					if (
						$sender === $charName
						|| $player->dimension === $this->config->main->dimension
						|| $player->online === true
					) {
						return AccessLevel::Guest;
					}
				}
			}
		}
		return null;
	}

	public function loadStackComponents(): void {
		/** @var array<string,array{class-string,class-string,\Closure}> */
		$types = [
			'RelayProtocol' => [
				NCA\RelayProtocol::class,
				RelayProtocolInterface::class,
				$this->registerRelayProtocol(...),
			],
			'Layer' => [
				NCA\RelayStackMember::class,
				RelayLayerInterface::class,
				$this->registerStackElement(...),
			],
			'Transport' => [
				NCA\RelayTransport::class,
				TransportInterface::class,
				$this->registerTransport(...),
			],
		];
		foreach ($types as $dir => $data) {
			foreach (get_declared_classes() as $class) {
				if (!is_a($class, $data[1], true)) {
					continue;
				}
				$spec = Util::getClassSpecFromClass($class, $data[0]);
				if (isset($spec)) {
					$data[2]($spec);
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
		$abbr = $this->relayGuildAbbreviation;
		if ($abbr !== self::NONE) {
			return $abbr;
		}
		return $this->config->general->orgName;
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

	/** Get a list of all available relay protocols */
	#[NCA\HandlesCommand('relay')]
	public function relayListProtocolsCommand(
		CmdContext $context,
		#[Str('list')] string $action,
		#[Regexp('protocols?', example: 'protocols')] string $subAction
	): void {
		$context->reply(
			$this->renderClassSpecOverview(
				$this->relayProtocols,
				'relay protocol',
				'protocol'
			)
		);
	}

	/** Get detailed information about a specific relay protocol */
	#[NCA\HandlesCommand('relay')]
	public function relayListProtocolDetailCommand(
		CmdContext $context,
		#[Str('list')] string $action,
		#[Str('protocol')] string $subAction,
		string $protocol
	): void {
		$context->reply(
			$this->renderClassSpecDetails(
				$this->relayProtocols,
				$protocol,
				'relay protocol',
			)
		);
	}

	/** Get a list of all available relay transports */
	#[NCA\HandlesCommand('relay')]
	public function relayListTransportsCommand(
		CmdContext $context,
		#[Str('list')] string $action,
		#[Regexp('transports?', example: 'transports')] string $subAction
	): void {
		$context->reply(
			$this->renderClassSpecOverview(
				$this->transports,
				'relay transport',
				'transport'
			)
		);
	}

	/** Get detailed information about a specific relay transport */
	#[NCA\HandlesCommand('relay')]
	public function relayListTransportDetailCommand(
		CmdContext $context,
		#[Str('list')] string $action,
		#[Str('transport')] string $subAction,
		string $transport
	): void {
		$context->reply(
			$this->renderClassSpecDetails(
				$this->transports,
				$transport,
				'relay transport',
			)
		);
	}

	/** Get a list of all available relay layers */
	#[NCA\HandlesCommand('relay')]
	public function relayListStacksCommand(
		CmdContext $context,
		#[Str('list')] string $action,
		#[Regexp('layers?', example: 'layers')] string $subAction
	): void {
		$context->reply(
			$this->renderClassSpecOverview(
				$this->stackElements,
				'relay layer',
				'layer'
			)
		);
	}

	/** Get detailed information about a specific relay layer */
	#[NCA\HandlesCommand('relay')]
	public function relayListStackDetailCommand(
		CmdContext $context,
		#[Str('list')] string $action,
		#[Str('layer')] string $subAction,
		string $layer
	): void {
		$context->reply(
			$this->renderClassSpecDetails(
				$this->stackElements,
				$layer,
				'relay layer'
			)
		);
	}

	/**
	 * Add a new relay, specifying in the order of transport, layers and protocol.
	 *
	 * A relay consists of (at the very least) a transport and a protocol.
	 * Use <highlight><symbol>quickrelay<end> to see examples of how to create a relay,
	 * or jump directly to the wiki.
	 */
	#[NCA\HandlesCommand('relay')]
	#[NCA\Help\Example(
		command: '<symbol>relay add test private-channel(channel="Privchannel") grcv2()'
	)]
	public function relayAddCommand(
		CmdContext $context,
		#[Str('add')] string $action,
		#[WordStr] string $name,
		string $spec
	): void {
		if (strlen($name) > 100) {
			$context->reply('The name of the relay must be 100 characters max.');
			return;
		}
		$relayConf = new RelayConfig(name: $name);
		$parser = new RelayLayerExpressionParser();
		try {
			$relayConf->layers = $parser->parse($relayConf, $spec);
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
		$layer = null;
		foreach ($relayConf->layers as $thisLayer) {
			$layers []= $thisLayer->toString();
			$layer = $thisLayer;
		}

		$blob = $this->quickRelayController->getRouteInformation(
			$name,
			isset($layer) && in_array($layer->layer, ['tyrbot', 'nadynative'], true)
		);
		$msg = "Relay <highlight>{$name}<end> added.";

		/**
		 * @mago-ignore analysis:redundant-logical-operation
		 *
		 * @phpstan-ignore-next-line
		 */
		if (!$this->messageHub->hasRouteFor($relay->getChannelName()) && !($context instanceof ProfileCommandReply)) {
			$help = Text::makeBlob('setup your routing', $blob);
			$msg .= " Make sure to {$help}, otherwise no messages will be exchanged.";
		}
		if ($relay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$msg .= ' This protocol supports relaying certain events. Use '.
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
			$this->db->insert($relayConf);
			foreach ($relayConf->layers as $layer) {
				$this->db->insert($layer);
				foreach ($layer->arguments as $argument) {
					$this->db->insert($argument);
				}
			}
			foreach ($relayConf->events as $event) {
				$this->db->insert($event);
			}
		} catch (Throwable $e) {
			if ($transactionActive) {
				throw $e;
			}
			$this->db->rollback();
			throw new Exception('Error saving the relay: ' . $e->getMessage(), 0, $e);
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
			throw new Exception('A relay with that name is already registered');
		}
		if (!$transactionActive) {
			$this->db->commit();
		}
		$relay->init(function () use ($relay): void {
			$this->logger->notice('Relay {relay_name} initialized', [
				'relay_name' => $relay->getName(),
			]);
		});
		return $relay;
	}

	public function deleteRelay(RelayConfig $relay): bool {
		/** @var list<UuidInterface> List of modifier-ids for the route */
		$layers = array_column($relay->layers, 'id');
		$transactionActive = false;
		try {
			$this->db->beginTransaction();
		} catch (Exception $e) {
			$transactionActive = true;
		}
		try {
			if (count($layers)) {
				$this->db->table(RelayLayerArgument::getTable())
					->whereIn('layer_id', $layers)
					->delete();
				$this->db->table(RelayLayer::getTable())
					->where('relay_id', $relay->id)
					->delete();
			}
			$this->db->table(RelayEvent::getTable())
				->where('relay_id', $relay->id)
				->delete();
			$this->db->table(RelayConfig::getTable())
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
		$liveRelay->deinit(function (Relay $relay): void {
			$this->logger->notice('Relay {relay_name} destroyed', [
				'relay_name' => $relay->getName(),
			]);
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
		$this->db->table(RelayLayerArgument::getTable())->truncate();
		$this->db->table(RelayLayer::getTable())->truncate();
		$this->db->table(RelayEvent::getTable())->truncate();
		$this->db->table(RelayConfig::getTable())->truncate();
		return count($relays);
	}

	/**
	 * Get a list of commands to create all current relays
	 *
	 * @return list<string>
	 */
	public function getRelayDump(): array {
		$relays = $this->getRelays();
		return array_map(static function (RelayConfig $relay): string {
			$msg = "!relay add {$relay->name}";
			foreach ($relay->layers as $layer) {
				$msg .= ' ' . $layer->toString();
			}
			if (count($relay->events) > 0) {
				$events = [];
				foreach ($relay->events as $event) {
					$events []= $event->toString();
				}
				$msg .= "\n!relay config {$relay->name} eventset ".
					implode(' ', $events);
			}
			return $msg;
		}, $relays);
	}

	/**
	 * Get the command that will create the relay
	 *
	 * You can use this to create the relay on another bot, or save it as backup
	 */
	#[NCA\HandlesCommand('relay')]
	public function relayDescribeIdCommand(
		CmdContext $context,
		#[Str('describe')] string $action,
		string $id
	): void {
		if (Uuid::isValid($id)) {
			$this->relayDescribeCommand($context, $id, null);
		} else {
			$this->relayDescribeCommand($context, null, $id);
		}
	}

	/**
	 * Get the command that will create the relay
	 *
	 * You can use this to create the relay on another bot, or save it as backup
	 */
	#[NCA\HandlesCommand('relay')]
	public function relayDescribeNameCommand(
		CmdContext $context,
		#[Str('describe')] string $action,
		#[NonNumberStr] string $name
	): void {
		$this->relayDescribeCommand($context, null, $name);
	}

	public function relayDescribeCommand(CmdContext $context, null|\Stringable|string $id, ?string $name): void {
		if (!$context->isDM()) {
			$context->reply(
				'Because the relay stack might contain passwords, '.
				'this command works only in tells.'
			);
			return;
		}
		$relay = isset($id)
			? $this->getRelay($id)
			: $this->getRelayByName($name??'');

		/** @var ?RelayConfig $relay */
		if (!isset($relay)) {
			$context->reply(
				'Relay <highlight>'.
				(isset($id) ? "{$id}" : ($name??'unknown')).
				'<end> not found.'
			);
			return;
		}
		$msg = "<symbol>relay add {$relay->name}";
		foreach ($relay->layers as $layer) {
			$msg .= ' ' . $layer->toString();
		}
		$context->reply($msg);
	}

	/** Get a list of all relays and their current status */
	#[NCA\HandlesCommand('relay')]
	public function relayListCommand(
		CmdContext $context,
		#[Str('list')] ?string $action
	): void {
		$relays = $this->getRelays();
		if (!count($relays)) {
			$context->reply('There are no relays defined.');
			return;
		}
		$blobs = [];
		foreach ($relays as $relay) {
			$blobs []= $this->renderRelay($relay);
		}
		$blob = implode("\n\n", $blobs);
		$wikiLink = Text::makeChatcmd(
			'Nadybot WIKI',
			'/start https://github.com/Nadybot/Nadybot/wiki/Routing#colors'
		);
		$blob .= "\n\n\n".
			'<i>For more information about how to color the individual tags and '.
			"texts, see the {$wikiLink}.</i>";
		$msg = Text::makeBlob('Relays (' . count($relays) . ')', $blob);
		$context->reply($msg);
	}

	/** Delete a relay */
	#[NCA\HandlesCommand('relay')]
	public function relayRemIdCommand(
		CmdContext $context,
		#[Remove] string $action,
		string $id
	): void {
		if (Uuid::isValid($id)) {
			$this->relayRemCommand($context, $id, null);
		} else {
			$this->relayRemCommand($context, null, $id);
		}
	}

	/** Delete a relay */
	#[NCA\HandlesCommand('relay')]
	public function relayRemNameCommand(
		CmdContext $context,
		#[Remove] string $action,
		#[NonNumberStr] string $name
	): void {
		$this->relayRemCommand($context, null, $name);
	}

	public function relayRemCommand(CmdContext $context, null|\Stringable|string $id, ?string $name): void {
		$relay = isset($id)
			? $this->getRelay($id)
			: $this->getRelayByName($name??'');
		if (!isset($relay)) {
			$context->reply(
				'Relay <highlight>'.
				(isset($id) ? "#{$id}" : ($name??'unknown')).
				'<end> not found.'
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

	/** Delete all relays */
	#[NCA\HandlesCommand('relay')]
	public function relayRemAllCommand(CmdContext $context, #[Str('remall', 'delall')] string $action): void {
		$numDeleted = $this->deleteAllRelays();
		$context->reply("<highlight>{$numDeleted}<end> relays deleted.");
	}

	/** Set if players from other relay-bots are treated as guests by this bot */
	#[NCA\HandlesCommand('relay')]
	public function relayGuestmodeCommand(CmdContext $context, #[Str('guestmode')] string $action, PUuid $id, bool $on): void {
		$id = $id();
		$relay = $this->getRelay($id);
		if (!isset($relay)) {
			$context->reply("Relay <highlight>#{$id}<end> not found.");
			return;
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_ONLINE_SYNC)) {
			$context->reply('This relay does not support sharing online lists.');
			return;
		}
		$oRelay->treatOnlineAsGuest = $on;
		$this->saveRelayProperties($relay);
		$status = $on ? '<green>on<end>' : '<red>off<end>';
		$context->reply("Treating online players from relay <highlight>{$oRelay->getName()}<end> as guests is now {$status}.");
	}

	/** Configure a relay. Only supported for nadynative */
	#[NCA\HandlesCommand('relay')]
	public function relayConfigNameCommand(
		CmdContext $context,
		#[Str('config')] string $action,
		#[NonNumberWord] string $name
	): void {
		$this->relayConfigCommand($context, null, $name);
	}

	/** Configure a relay. Only supported for nadynative */
	#[NCA\HandlesCommand('relay')]
	public function relayConfigIdCommand(CmdContext $context, #[Str('config')] string $action, #[WordStr] string $id): void {
		if (Uuid::isValid($id)) {
			$this->relayConfigCommand($context, $id, null);
		} else {
			$this->relayConfigCommand($context, null, $id);
		}
	}

	public function relayConfigCommand(CmdContext $context, null|\Stringable|string $id, ?string $name): void {
		$relay = isset($id)
			? $this->getRelay($id)
			: $this->getRelayByName($name??'');
		if (!isset($relay)) {
			$context->reply(
				'Relay <highlight>'.
				(isset($id) ? "{$id}" : ($name??'unknown')).
				'<end> not found.'
			);
			return;
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$context->reply('This relay has nothing to configure.');
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
		$blob .= '<header2>Syncable events<end>';
		foreach ($events as $event) {
			$eConf = $relay->getEvent($event->name) ?? new RelayEvent(relay_id: $relay->id, event: $event->name);
			$line = "\n<tab><highlight>{$event->name}<end>:";
			foreach (EventDirection::cases() as $type) {
				if ($eConf->getEnabled($type)) {
					$line .= " <on>{$type->name}<end> [".
						Text::makeChatcmd(
							'disable',
							"/tell <myname> relay config {$relay->name} eventmod {$event->name} disable {$type->value}"
						) . ']';
				} else {
					$line .= " <off>{$type->name}<end> [".
						Text::makeChatcmd(
							'enable',
							"/tell <myname> relay config {$relay->name} eventmod {$event->name} enable {$type->value}"
						) . ']';
				}
			}
			$blob .= $line;
			if (isset($event->description)) {
				$blob .= "\n<tab><tab><i>{$event->description}</i>";
			}
			$blob .= "\n";
		}
		$msg = Text::makeBlob("Relay configuration for {$relay->name}", $blob);
		$context->reply($msg);
	}

	/** Allow or forbid incoming or outgoing a syncable event for a relay */
	#[NCA\HandlesCommand('relay')]
	public function relayConfigEventmodCommand(
		CmdContext $context,
		#[Str('config')] string $action,
		#[WordStr] string $name,
		#[Str('eventmod')] string $subAction,
		#[WordStr] string $event,
		bool $enable,
		EventDirection $direction
	): void {
		$relay = $this->getRelayByName($name);
		if (!isset($relay)) {
			$context->reply("Relay <highlight>{$name}<end> not found.");
			return;
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$context->reply(
				"The relay <highlight>{$relay->name}<end> uses a protocol which ".
				'does not support syncing events.'
			);
			return;
		}
		$statusMsg = $enable ? '<on>enabled<end>' : '<off>disabled<end>';
		if ($this->changeRelayEventStatus($relay, $event, $direction, $enable)) {
			$context->reply(
				"Successfully {$statusMsg} {$direction->value} events of type ".
				"<highlight>{$event}<end> for relay <highlight>{$relay->name}<end>."
			);
			return;
		}
		$context->reply(
			"{$direction->name} events of type <highlight>{$event}<end> ".
			"were already {$statusMsg} for relay <highlight>{$relay->name}<end>."
		);
	}

	/** Batch allow or forbid incoming or outgoing a syncable events for a relay */
	#[NCA\HandlesCommand('relay')]
	public function relayConfigEventsetCommand(
		CmdContext $context,
		#[Str('config')] string $action,
		#[WordStr] string $name,
		#[Str('eventset')] string $subAction,
		#[Regexp("[a-z()_-]+\s+(?:IO|O|I)", example: '&lt;event I|O|IO&gt;')] ?string ...$events
	): void {
		$relay = $this->getRelayByName($name);
		if (!isset($relay)) {
			$context->reply("Relay <highlight>{$name}<end> not found.");
			return;
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			$context->reply(
				"The relay <highlight>{$relay->name}<end> uses a protocol which ".
				'does not support syncing events.'
			);
			return;
		}
		$eventConfigs = [];
		foreach ($events as $eventConfig) {
			[$eventName, $dir] = Safe::pregSplit("/\s+/", $eventConfig??'');
			$eventConfigs[$eventName] = $dir;
		}
		$this->db->table(RelayEvent::getTable())
			->where('relay_id', $relay->id)
			->delete();
		$relay->events = [];
		foreach ($eventConfigs as $eventName => $dir) {
			$event = new RelayEvent(
				relay_id: $relay->id,
				event: (string)$eventName, // @phpstan-ignore-line
				incoming: stripos($dir, 'I') !== false,
				outgoing: stripos($dir, 'O') !== false,
			);
			$this->db->insert($event);
			$relay->addEvent($event);
		}
		$this->relays[$relay->name]->setEvents($relay->events);
		$context->reply("Relay events set for <highlight>{$relay->name}<end>.");
	}

	/**
	 * Force syncing a command via all supporting relays
	 *
	 * Note: This will only force the outgoing event to be sent, not that
	 * the other relays allow receiving this event.
	 */
	#[NCA\HandlesCommand('sync')]
	#[NCA\Untestable]
	public function syncCommand(CmdContext $context, string $command): void {
		$context->message = $command;
		$context->forceSync = true;
		$this->commandManager->syncProcessCmd($context);
	}

	/**
	 * Read all defined relays from the database
	 *
	 * @return list<RelayConfig>
	 */
	public function getRelays(): array {
		/** @var Collection<string,Collection<int,RelayLayerArgument>> */
		$arguments = $this->db->table(RelayLayerArgument::getTable())
			->orderBy('id')
			->asObj(RelayLayerArgument::class)
			->groupByString('layer_id');

		/** @var Collection<string,Collection<int,RelayLayer>> */
		$layers = $this->db->table(RelayLayer::getTable())
			->orderBy('id')
			->asObj(RelayLayer::class)
			->each(static function (RelayLayer $layer) use ($arguments): void {
				/** @var Collection<int,RelayLayerArgument> */
				$empty = new Collection();
				$layer->arguments = $arguments->get($layer->id->toString(), $empty)->toList();
			})
			->groupByString('relay_id');

		/** @var Collection<string,Collection<int,RelayEvent>> */
		$events = $this->db->table(RelayEvent::getTable())
			->orderBy('id')
			->asObj(RelayEvent::class)
			->groupByString('relay_id');
		$relays = $this->db->table(RelayConfig::getTable())
			->orderBy('id')
			->asObj(RelayConfig::class)
			->each(static function (RelayConfig $relay) use ($layers, $events): void {
				/** @var Collection<int,RelayLayer> */
				$emptyLayers = new Collection();
				$relay->layers = $layers->get($relay->id->toString(), $emptyLayers)->toList();

				/** @var Collection<int,RelayEvent> */
				$emptyEvents = new Collection();
				$relay->events = $events->get($relay->id->toString(), $emptyEvents)->toList();
			})
			->toList();

		/** @var list<RelayConfig> */
		return $relays;
	}

	/** Read a relay by its ID */
	public function getRelay(\Stringable|string $id): ?RelayConfig {
		$relay = $this->db->table(RelayConfig::getTable())
			->where('id', (string)$id)
			->firstObj(RelayConfig::class);
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	/** Read a relay by its name */
	public function getRelayByName(string $name): ?RelayConfig {
		$relay = $this->db->table(RelayConfig::getTable())
			->where('name', $name)
			->firstObj(RelayConfig::class);
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	public function addRelay(Relay $relay): bool {
		if (isset($this->relays[$relay->getName()])) {
			return false;
		}
		$relay->setMessageQueueSize($this->relayQueueSize);
		$this->relays[$relay->getName()] = $relay;
		return true;
	}

	/**
	 * Get a fully configured relay layer or null if not possible
	 *
	 * @param string               $name   Name of the layer
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
					case ParamType::Bool:
						if (!in_array($value, ['true', 'false'], true)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be 'true' or 'false', ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= $value === 'true';
						unset($params[$parameter->name]);
						break;
					case ParamType::Int:
						if (!Safe::pregMatches("/^[+-]?\d+/", $value)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be a number, ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= (int)$value;
						unset($params[$parameter->name]);
						break;
					case ParamType::StringArray:
						$arguments []= (array)$value;
						unset($params[$parameter->name]);
						break;
					default:
						$arguments []= $value;
						unset($params[$parameter->name]);
				}
			} elseif ($parameter->required) {
				throw new Exception(
					"Missing required argument <highlight>{$parameter->name}<end> ".
					"to <highlight>{$name}<end>."
				);
			} else {
				$ref = new ReflectionMethod($spec->class, '__construct');
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
		if (count($params) > 0) {
			throw new Exception(
				'Unknown parameter' . (count($params) > 1 ? 's' : '').
				' <highlight>'.
				(new Collection(array_keys($params)))
					->join('<end>, <highlight>', '<end> and <highlight>').
				"<end> to <highlight>{$name}<end>."
			);
		}
		$class = $spec->class;
		try {
			/**
			 * @psalm-suppress MixedMethodCall
			 *
			 * @mago-ignore analysis:unknown-class-instantiation
			 */
			$result = new $class(...$arguments);
			Registry::injectDependencies($result);
			return $result;
		} catch (Throwable $e) {
			throw new Exception("There was an error setting up the {$name} layer: " . $e->getMessage(), 0, $e);
		}
	}

	/** List all relay transports */
	#[
		Http\Api('/relay-component/transport'),
		Http\GET,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 200, class: 'ClassSpec[]', desc: 'The available relay transport layers')
	]
	public function apiGetTransportsEndpoint(Request $request): Response {
		return ApiResponse::create(array_values($this->transports));
	}

	/** List all relay layers */
	#[
		Http\Api('/relay-component/layer'),
		Http\GET,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 200, class: 'ClassSpec[]', desc: 'The available generic relay layers')
	]
	public function apiGetLayersEndpoint(Request $request): Response {
		return ApiResponse::create(array_values($this->stackElements));
	}

	/** List all relay protocols */
	#[
		Http\Api('/relay-component/protocol'),
		Http\GET,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 200, class: 'ClassSpec[]', desc: 'The available relay protocols')
	]
	public function apiGetProtocolsEndpoint(Request $request): Response {
		return ApiResponse::create(array_values($this->relayProtocols));
	}

	/** List all relays */
	#[
		Http\Api('/relay'),
		Http\GET,
		Http\AccessLevelFrom('relay'),
		Http\ApiResult(code: 200, class: 'RelayConfig[]', desc: 'The configured relays')
	]
	public function apiGetRelaysEndpoint(Request $request): Response {
		return ApiResponse::create($this->getRelays());
	}

	/**
	 * Get a single relay
	 *
	 * @param string $relay The name of the relay
	 */
	#[
		Http\Api('/relay/%s'),
		Http\GET,
		Http\AccessLevelFrom('relay'),
		Http\ApiResult(code: 200, class: 'RelayConfig', desc: 'The configured relay'),
		Http\ApiResult(code: 404, desc: 'Relay not found')
	]
	public function apiGetRelayByNameEndpoint(Request $request, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		return ApiResponse::create($relay);
	}

	/**
	 * Get a single relay's event config
	 *
	 * @param string $relay The name of the relay
	 */
	#[
		Http\Api('/relay/%s/events'),
		Http\GET,
		Http\AccessLevelFrom('relay'),
		Http\ApiResult(code: 200, class: 'RelayEvent[]', desc: 'The configured relay events'),
		Http\ApiResult(code: 404, desc: 'Relay not found')
	]
	public function apiGetRelayEventsByNameEndpoint(Request $request, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		return ApiResponse::create($relay->events);
	}

	/**
	 * Get a single relay's event config
	 *
	 * @param string $relay The name of the relay
	 */
	#[
		Http\Api('/relay/%s/events'),
		Http\PUT,
		Http\AccessLevelFrom('relay'),
		Http\RequestBody(class: 'RelayEvent[]', desc: 'The event configuration', required: true),
		Http\ApiResult(code: 204, desc: 'The event configuration was set'),
		Http\ApiResult(code: 404, desc: 'Relay not found')
	]
	public function apiPutRelayEventsByNameEndpoint(Request $request, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_array($body)) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		foreach ($body as &$item) {
			if (is_array($item)) {
				$item['relay_id'] ??= $relay->id;
			}
		}

		try {
			/**
			 * @psalm-suppress PossiblyInvalidArgument
			 *
			 * @mago-ignore analysis:less-specific-nested-argument-type
			 */
			$events = Hydrator::hydrateObjects(RelayEvent::class, $body)->toArray();
		} catch (Throwable $e) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		$this->db->awaitBeginTransaction();
		$oldEvents = $relay->events;
		try {
			$this->db->table(RelayEvent::getTable())
				->where('relay_id', $relay->id)
				->delete();
			$relay->events = [];

			foreach ($events as $event) {
				$this->db->insert($event);
				$relay->addEvent($event);
			}
			$this->relays[$relay->name]->setEvents($relay->events);
		} catch (Throwable $e) {
			$this->db->rollback();
			$relay->events = $oldEvents;
			return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
		}
		$this->db->commit();
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/**
	 * Get a single relay's event config
	 *
	 * @param string $relay The name of the relay
	 */
	#[
		Http\Api('/relay/%s/events'),
		Http\PATCH,
		Http\AccessLevelFrom('relay'),
		Http\RequestBody(class: 'RelayEvent', desc: 'The changed event configuration for one event', required: true),
		Http\ApiResult(code: 204, desc: 'The event configuration was set'),
		Http\ApiResult(code: 404, desc: 'Relay not found')
	]
	public function apiPatchRelayEventsByNameEndpoint(Request $request, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$oRelay = $this->relays[$relay->name]??null;
		if (!isset($oRelay) || !$oRelay->protocolSupportsFeature(RelayProtocolInterface::F_EVENT_SYNC)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$body = $request->getAttribute(WebserverController::BODY);
		try {
			Type\dict(Type\string(), Type\mixed())->assert($body);
		} catch (\Exception) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		try {
			/** @var array<string,mixed> */
			$oldData = Hydrator::serialize($relay);
			$update = Util::mergeArraysRecursive($oldData, $body);
			$event = Hydrator::hydrate(RelayEvent::class, $update);
		} catch (Throwable $e) {
			return new Response(
				status: HttpStatus::UNPROCESSABLE_ENTITY,
				body: $e->getMessage()
			);
		}
		if (isset($event->incoming)) {
			$this->changeRelayEventStatus($relay, $event->event, EventDirection::Incoming, $event->incoming);
		}
		if (isset($event->outgoing)) {
			$this->changeRelayEventStatus($relay, $event->event, EventDirection::Outgoing, $event->outgoing);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/**
	 * Delete a relay
	 *
	 * @param string $relay The name of the relay
	 */
	#[
		Http\Api('/relay/%s'),
		Http\DELETE,
		Http\AccessLevelFrom('relay'),
		Http\ApiResult(code: 204, desc: 'The relay was deleted'),
		Http\ApiResult(code: 404, desc: 'Relay not found')
	]
	public function apiDelRelayByNameEndpoint(Request $request, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		try {
			$this->deleteRelay($relay);
		} catch (Exception $e) {
			return new Response(
				status: HttpStatus::INTERNAL_SERVER_ERROR,
				body: $e->getMessage()
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/**
	 * Get a relay's status
	 *
	 * @param string $relay The name of the relay
	 */
	#[
		Http\Api('/relay/%s/status'),
		Http\GET,
		Http\AccessLevelFrom('relay'),
		Http\ApiResult(code: 200, class: 'RelayStatus', desc: 'The status message of the relay'),
		Http\ApiResult(code: 404, desc: 'Relay not found')
	]
	public function apiGetRelayStatusByNameEndpoint(Request $request, string $relay): Response {
		if (!isset($this->relays[$relay])) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		return ApiResponse::create($this->relays[$relay]->getStatus());
	}

	/** Create a new relay */
	#[
		Http\Api('/relay'),
		Http\POST,
		Http\AccessLevelFrom('relay'),
		Http\ApiResult(code: 204, desc: 'Relay created successfully')
	]
	public function apiCreateRelay(Request $request): Response {
		$body = $request->getAttribute(WebserverController::BODY);
		try {
			Type\dict(Type\string(), Type\mixed())->assert($body);
			$relay = Hydrator::hydrate(RelayConfig::class, $body);
		} catch (Throwable) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		try {
			$this->createRelay($relay);
		} catch (Exception $e) {
			return new Response(
				status: HttpStatus::INTERNAL_SERVER_ERROR,
				body: $this->text->formatMessage($e->getMessage())
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** List all relay layers */
	#[
		Http\Api('/relay-component/event'),
		Http\GET,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 200, class: 'EventType[]', desc: 'The available non-routable relay events')
	]
	public function apiGetEventsEndpoint(Request $request): Response {
		return ApiResponse::create($this->getRegisteredSyncEvents());
	}

	/** @param array<string,ClassSpec> $specs */
	protected function renderClassSpecOverview(array $specs, string $name, string $subCommand): string {
		$count = count($specs);
		if (!$count) {
			return "No {$name}s available.";
		}
		$blobs = [];
		ksort($specs);
		foreach ($specs as $spec) {
			$description = $spec->description ?? 'Someone forgot to add a description';
			$entry = "<header2>{$spec->name}<end>\n".
				'<tab>'.
				implode("\n<tab>", explode("\n", trim($description)));
			if (count($spec->params)) {
				$entry .= "\n<tab>[" . Text::makeChatcmd('details', "/tell <myname> relay list {$subCommand} {$spec->name}") . ']';
			}
			$blobs []= $entry;
		}
		$blob = implode("\n\n", $blobs);
		return Text::makeBlob("Available {$name}s ({$count})", $blob);
	}

	/** @param array<string,ClassSpec> $specs */
	protected function renderClassSpecDetails(array $specs, string $key, string $name): string {
		$spec = $specs[$key] ?? null;
		if (!isset($spec)) {
			return "No {$name} <highlight>{$key}<end> found.";
		}
		$refClass = new ReflectionClass($spec->class);
		try {
			$refConstr = $refClass->getMethod('__construct');
			$refParams = $refConstr->getParameters();
		} catch (ReflectionException $e) {
			$refParams = [];
		}
		$description = $spec->description ?? 'Someone forgot to add a description';
		$blob = "<header2>Description<end>\n".
			'<tab>' . implode("\n<tab>", explode("\n", trim($description))).
			"\n";
		if (count($spec->params)) {
			$blob .= "\n<header2>Parameters<end>\n";
			$parNum = 0;
			foreach ($spec->params as $param) {
				$type = ($param->type === ParamType::Secret) ? ParamType::String : $param->type;
				$blob .= "<tab><green>{$type->value}<end> <highlight>{$param->name}<end>";
				if (!$param->required) {
					if (isset($refParams[$parNum]) && $refParams[$parNum]->isDefaultValueAvailable()) {
						try {
							$blob .= ' (optional, default='.
								json_encode(
									$refParams[$parNum]->getDefaultValue(),
									\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR|\JSON_INVALID_UTF8_SUBSTITUTE
								) . ')';
						} catch (JsonException $e) {
							$blob .= ' (optional)';
						}
					} else {
						$blob .= ' (optional)';
					}
				}
				$parNum++;
				$blob .= "\n<tab><i>".
					implode("</i>\n<tab><i>", explode("\n", $param->description ?? 'No description')).
					"</i>\n\n";
			}
		}
		return Text::makeBlob(
			"Detailed description for {$spec->name}",
			$blob
		);
	}

	protected function saveRelayProperties(RelayConfig $relay): bool {
		$oRelay = $this->relays[$relay->name] ?? null;
		if (!isset($oRelay)) {
			return false;
		}
		$refClass = new ReflectionClass($oRelay);
		foreach ($refClass->getProperties() as $refProp) {
			foreach ($refProp->getAttributes(RelayProp::class) as $refAttr) {
				$attr = $refAttr->newInstance();
				try {
					$value = json_encode($refProp->getValue($oRelay));
				} catch (JsonException $e) {
					return false;
				}
				$this->changeRelayProperty($relay, $attr->name, $value);
			}
		}
		return true;
	}

	protected function changeRelayEventStatus(RelayConfig $relay, string $eventName, EventDirection $direction, bool $enable): bool {
		$oldEvent = $event = $relay->getEvent($eventName);
		if (!isset($event)) {
			if ($enable === false) {
				return false;
			}
			$event = new RelayEvent(event: $eventName, relay_id: $relay->id);
		}
		if ($event->getEnabled($direction) === $enable) {
			return false;
		}
		$event->setEnabled($direction, $enable);
		if (isset($oldEvent)) {
			if ($event->incoming === false && $event->outgoing === false) {
				$this->db->table(RelayEvent::getTable())->delete($event->id);
				$relay->deleteEvent($eventName);
			} else {
				$this->db->update($event);
			}
		} else {
			$this->db->insert($event);
			$relay->addEvent($event);
		}
		$this->relays[$relay->name]->setEvents($relay->events);
		return true;
	}

	/**
	 * Get a list of all registered sync events as array with names
	 *
	 * @return list<EventType>
	 */
	protected function getRegisteredSyncEvents(): array {
		return array_values(
			array_filter(
				$this->eventManager->getEventTypes(),
				static function (EventType $event): bool {
					return fnmatch('sync(*)', $event->name, \FNM_CASEFOLD);
				}
			)
		);
	}

	/** Add layers and args to a relay from the DB */
	protected function completeRelay(RelayConfig $relay): void {
		$relay->layers = $this->db->table(RelayLayer::getTable())
			->where('relay_id', $relay->id)
			->orderBy('id')
			->asObjArr(RelayLayer::class);
		foreach ($relay->layers as $layer) {
			$layer->arguments = $this->db->table(RelayLayerArgument::getTable())
				->where('layer_id', $layer->id)
				->orderBy('id')
				->asObjArr(RelayLayerArgument::class);
		}
		$relay->events = $this->db->table(RelayEvent::getTable())
			->where('relay_id', $relay->id)
			->orderBy('id')
			->asObjArr(RelayEvent::class);
	}

	protected function createRelayFromDB(RelayConfig $conf): Relay {
		if (count($conf->layers) < 2) {
			throw new Exception(
				'Every relay must have at least 1 transport and 1 protocol.'
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

		/** @var list<RelayLayerInterface> $stack */
		$stack = [];
		$transport = array_shift($conf->layers);
		// @mago-ignore analysis:possibly-null-argument
		$spec = $this->transports[strtolower($transport->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$transport->layer}<end> is not a ".
				'known transport for relaying. Perhaps the order was wrong?'
			);
		}

		/** @var TransportInterface */
		$transportLayer = $this->getRelayLayer(
			// @mago-ignore analysis:possibly-null-argument
			$transport->layer,
			// @mago-ignore analysis:possible-method-access-on-null
			$transport->getKVArguments(),
			$spec
		);

		for ($i = 0; $i < count($conf->layers)-1; $i++) {
			$layerName = strtolower($conf->layers[$i]->layer);
			$spec = $this->stackElements[$layerName] ?? null;
			if (!isset($spec)) {
				throw new Exception(
					"<highlight>{$layerName}<end> is not a ".
					'known layer for relaying. Perhaps the order was wrong?'
				);
			}
			$rLayer = $this->getRelayLayer(
				$layerName,
				$conf->layers[$i]->getKVArguments(),
				$spec
			);
			if ($rLayer instanceof RelayLayerInterface) {
				$stack []= $rLayer;
			}
		}

		$proto = array_pop($conf->layers);
		assert(isset($proto));
		$spec = $this->relayProtocols[strtolower($proto->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$proto->layer}<end> is not a ".
				'known relay protocol. Perhaps the order was wrong?'
			);
		}

		/** @var RelayProtocolInterface */
		$protocolLayer = $this->getRelayLayer(
			$proto->layer,
			$proto->getKVArguments(),
			$spec
		);

		$relayObj = new Relay(
			name: $conf->name,
			transport: $transportLayer,
			relayProtocol: $protocolLayer,
			stack: $stack,
			events: $conf->events,
		);
		return $this->loadRelayProperties($conf, $relayObj);
	}

	protected function loadRelayProperties(RelayConfig $config, Relay $relay): Relay {
		$relayProps = $this->db->table(RelayProperty::getTable())
			->where('relay_id', $config->id)
			->asObj(RelayProperty::class)
			->keyByString('property');

		$refClass = new ReflectionClass($relay);
		foreach ($refClass->getProperties() as $refProp) {
			foreach ($refProp->getAttributes(RelayProp::class) as $refAttr) {
				$attr = $refAttr->newInstance();

				$dbProp = $relayProps->get($attr->name, null);
				if (isset($dbProp)) {
					$this->logger->info('Setting {relay}.{property} to {value}', [
						'relay' => $relay->getName(),
						'property' => $refProp->getName(),
						'value' => $dbProp->value,
					]);
					try {
						if (isset($dbProp->value)) {
							$value = json_decode($dbProp->value);
							$refProp->setValue($relay, $value);
						}
					} catch (JsonException $e) {
						$this->logger->error('Error setting {relay}.{property}: {error}', [
							'relay' => $relay->getName(),
							'property' => $refProp->getName(),
							'error' => $e->getMessage(),
							'exception' => $e,
						]);
					}
				}
			}
		}
		return $relay;
	}

	private function changeRelayProperty(RelayConfig $relay, string $property, string $value): void {
		$this->db->upsert(new RelayProperty(
			relay_id: $relay->id,
			property: $property,
			value: $value,
		));
	}

	/** Return the textual representation with status for a single relay */
	private function renderRelay(RelayConfig $relay): string {
		$blob = "<header2>{$relay->name}<end>\n";
		if (count($relay->layers) === 0) {
			return $blob . '<tab>- empty -';
		}
		$layer = $relay->layers[0];
		if (isset($this->transports[$layer->layer])) {
			$secrets = $this->transports[$layer->layer]->getSecrets();
			$blob .= '<tab>Transport: <highlight>' . $layer->toString('transport', $secrets) . "<end>\n";
		} else {
			$blob .= "<tab>Transport: <highlight>{$relay->layers[0]->layer}(<red>error<end>)<end>\n";
		}
		for ($i = 1; $i < count($relay->layers)-1; $i++) {
			$layer = $relay->layers[$i];
			if (isset($this->stackElements[$layer->layer])) {
				$secrets = $this->stackElements[$layer->layer]->getSecrets();
				$blob .= '<tab>Layer: <highlight>' . $layer->toString('layer', $secrets) . "<end>\n";
			} else {
				$blob .= "<tab>Layer: <highlight>{$layer->layer}(<red>error<end>)<end>\n";
			}
		}
		$layer = $relay->layers[count($relay->layers)-1];
		$layerName = $layer->layer;
		if (isset($this->relayProtocols[$layerName])) {
			$secrets = $this->relayProtocols[$layerName]->getSecrets();
			$blob .= '<tab>Protocol: <highlight>' . $layer->toString('protocol', $secrets) . "<end>\n";
		} else {
			$blob .= "<tab>Protocol: <highlight>{$layerName}(<red>error<end>)<end>\n";
		}
		$live = $this->relays[$relay->name] ?? null;
		if (isset($live)) {
			$blob .= '<tab>Status: ' . $live->getStatus()->toString();
		} else {
			$blob .= '<tab>Status: <red>error<end>';
		}
		$delLink = Text::makeChatcmd(
			'delete',
			"/tell <myname> relay rem {$relay->id}"
		);
		$descrLink = Text::makeChatcmd(
			'describe',
			"/tell <myname> relay describe {$relay->id}"
		);
		$blob .= " [{$delLink}] [{$descrLink}]\n";

		if (isset($live) && $live->protocolSupportsFeature(RelayProtocolInterface::F_ONLINE_SYNC) === true) {
			$link = Text::makeChatcmd(
				$live->treatOnlineAsGuest ? 'disable' : 'enable',
				"/tell <myname> relay guestmode {$relay->id} ".
					($live->treatOnlineAsGuest ? 'off' : 'on')
			);
			$blob .= '<tab>Treat online players as guests: <highlight>'.
				($live->treatOnlineAsGuest ? 'yes' : 'no') . "<end> [{$link}]\n";
		}

		$blob .= "<tab>Colors:\n";
		$blob .= '<tab><tab>' . $this->getExampleMessage(
			$relay,
			[new Source(Source::ORG, 'example', 'ORG', 5)]
		) . "\n";
		$blob .= '<tab><tab>' . $this->getExampleMessage(
			$relay,
			[
				new Source(Source::ORG, 'example', 'ORG', 5),
				new Source(Source::PRIV, 'example', 'Guest', 5),
			]
		) . "\n";
		return $blob;
	}

	/**
	 * @param list<Source> $source
	 *
	 * @psalm-param non-empty-list<Source> $source
	 */
	private function getExampleMessage(RelayConfig $relay, array $source): string {
		$rEvent = new RoutableMessage('xxx');
		$rEvent->setCharacter(new Character('Nady'));
		$rEvent->path = [
			new Source(Source::RELAY, $relay->name),
			...$source,
		];
		$lastHop = $source[count($source)-1];
		$renderedPath = $this->messageHub->renderPath($rEvent, '*', true);
		$msgColor = $this->messageHub->getTextColor($rEvent, Source::ORG);
		if (strlen($msgColor)) {
			$example = "{$msgColor}This is what text from the ".
				strtolower($lastHop->label ?? 'test') . '-chat looks like.<end>';
		} else {
			$example = 'Text from the ' . strtolower($lastHop->label ?? 'test').
				'-chat has no color set.';
		}
		$tagLink = Text::makeChatcmd(
			"{$lastHop->label}-tag color",
			"/tell <myname> route color tag pick {$lastHop->type} via relay({$relay->name})"
		);
		$textLink = Text::makeChatcmd(
			'text color',
			"/tell <myname> route color text pick {$lastHop->type} via relay({$relay->name})"
		);
		$blob = "{$renderedPath}{$example} [{$tagLink}] [{$textLink}]";
		return $blob;
	}
}
