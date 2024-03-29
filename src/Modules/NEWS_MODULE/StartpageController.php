<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Closure;
use DateInterval;
use DateTime;
use DateTimeZone;
use Generator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nadybot\Core\{
	AOChatEvent,
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandReply,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Modules\BAN\BanController,
	Nadybot,
	ParamClass\PRemove,
	Registry,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
	HttpProtocolWrapper,
	Request,
	Response,
	WebChatConverter,
};
use ReflectionClass;
use ReflectionMethod;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "startpage",
		accessLevel: "mod",
		description: "configures the personal startpage",
	),
	NCA\DefineCommand(
		command: "start",
		accessLevel: "member",
		description: "Shows your personal startpage",
	),
]
class StartpageController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public BanController $banController;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public WebChatConverter $webChatConverter;

	/** The tiles to show on the startpage */
	#[NCA\Setting\Text(mode: "noedit")]
	public string $startpageLayout = "";

	/** The message when sending the startpage to people */
	#[NCA\Setting\Text(help: "startpage_startmsg.txt")]
	public string $startpageStartmsg = "Welcome, {name}!";

	/** When to show non-org-members the startpage */
	#[NCA\Setting\Options(options: [
		'Do not show to non-org-members' => 0,
		'When Logging in' => 1,
		'When joining the private channel' => 2,
	])]
	public int $startpageShowMembers = 2;

	/** @var array<string,NewsTile> */
	protected array $tiles = [];

	#[NCA\Setup]
	public function setup(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$class = new ReflectionClass($instance);
			foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				$this->parseRefMethod($instance, $method);
			}
		}
	}

	#[NCA\Event(
		name: "logOn",
		description: "Show startpage to (org) members logging in"
	)]
	public function logonEvent(UserStateEvent $eventObj): Generator {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady()
			|| !is_string($sender)
			|| $eventObj->wasOnline !== false
		) {
			return;
		}
		if (isset($this->chatBot->guildmembers[$sender])) {
			$this->showStartpage($sender, $this->getMassTell($sender));
			return;
		}
		$uid = yield $this->chatBot->getUid2($sender);
		if ($uid === null) {
			return;
		}
		if ($this->startpageShowMembers !== 1) {
			return;
		}
		if ($this->accessManager->getAccessLevelForCharacter($sender) === "all") {
			return;
		}
		if (yield $this->banController->isOnBanlist($uid)) {
			return;
		}
		$this->showStartpage($sender, $this->getMassTell($sender));
	}

	#[NCA\Event(
		name: "joinPriv",
		description: "Show startpage to players joining private channel"
	)]
	public function privateChannelJoinEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady() || !is_string($sender) || isset($this->chatBot->guildmembers[$sender])) {
			return;
		}
		if ($this->startpageShowMembers !== 2) {
			return;
		}
		$this->showStartpage($sender, $this->getMassTell($sender));
	}

	#[
		NCA\NewsTile(
			name: "time",
			description: "Shows the current date and time in UTC and game",
			example: "<header2>Time<end>\n".
				"<tab>Current time: <highlight>Mon, 18-Oct-2021 14:15:16<end> (RK year 29495)"
		)
	]
	public function timeTile(string $sender, callable $callback): void {
		$seeMoreLink = $this->text->makeChatcmd("see more", "/tell <myname> time");
		$time = new DateTime('now', new DateTimeZone("UTC"));
		$aoTime = (clone $time)->add(new DateInterval("P27474Y"));
		$blob = "<header2>Time [{$seeMoreLink}]<end>\n".
			"<tab>Current time: <highlight>" . $time->format("l, d-M-Y H:i:s T") . "<end> ".
			"(RK year " . $aoTime->format("Y") .")";
		$callback($blob);
	}

	public function registerNewsTile(NewsTile $tile): bool {
		if (isset($this->tiles[$tile->name])) {
			return false;
		}
		$this->tiles[$tile->name] = $tile;
		return true;
	}

	public function setTiles(string ...$tileNames): bool {
		$coll = (new Collection($tileNames))->unique();
		if ($coll->count() !== count($tileNames)) {
			throw new DuplicateTileException("You cannot display a news tile more than once.");
		}
		$invalid = $coll->filter(function (string $tileName): bool {
			return !isset($this->tiles[$tileName]);
		});
		if ($invalid->isNotEmpty()) {
			throw new InvalidTileException($invalid->first() . " is not a valid tile.");
		}
		return $this->settingManager->save("startpage_layout", $coll->flatten()->join(";"));
	}

	/**
	 * Get a list of all registered tiles
	 *
	 * @return array<string,NewsTile>
	 */
	public function getTiles(): array {
		return $this->tiles;
	}

	/**
	 * Get a list of all active tiles that are valid
	 *
	 * @return array<string,NewsTile>
	 */
	public function getActiveLayout(): array {
		$tileString = $this->startpageLayout;
		if ($tileString === "") {
			return [];
		}
		$tileNames = explode(";", $tileString);
		$tiles = [];
		foreach ($tileNames as $tileName) {
			if (isset($this->tiles[$tileName])) {
				$tiles[$tileName] = $this->tiles[$tileName];
			}
		}
		return $tiles;
	}

	public function getStartpageString(string $sender): string {
		$msg = $this->startpageStartmsg;
		$repl = [
			"{name}" => $sender,
			"{myname}" => "<myname>",
			"{orgname}" => "<myguild>",
			"{date}" => (new DateTime())->format("l, d-M-Y"),
			"{time}" => (new DateTime())->format("H:i:s"),
		];
		return str_replace(array_keys($repl), array_values($repl), $msg);
	}

	public function showStartpage(string $sender, CommandReply $sendto, bool $showEmpty=false): void {
		$tiles = $this->getActiveLayout();
		if (empty($tiles)) {
			if ($showEmpty) {
				$sendto->reply("Your startpage is currently <highlight>empty<end>.");
			}
			return;
		}
		$callResults = [];
		$callNum = 0;
		$callback = function (int $numCall, ?string $text, string $name) use (&$callResults, $tiles, $sendto, $sender, $showEmpty): void {
			$callResults[$numCall] = isset($text) ? trim($text) : null;
			$this->logger->debug("Callback for {name} received", [
				"name" => $name,
				"data" => $text,
			]);
			if (count($callResults) < count($tiles)) {
				return;
			}
			$this->logger->info("All start callbacks finished");
			ksort($callResults, SORT_NUMERIC);
			$dataParts = array_filter(array_values($callResults));
			if (empty($dataParts)) {
				if ($showEmpty) {
					$sendto->reply("Your startpage is currently <highlight>empty<end>.");
				}
				return;
			}
			$blob = join("\n\n", $dataParts);
			$msg = $this->text->makeBlob($this->getStartpageString($sender), $blob);
			$sendto->reply($msg);
		};
		foreach ($tiles as $name => $tile) {
			$this->logger->info("Calling callback of {tile}", [
				"tile" => $name,
			]);
			$tile->call($sender, $this->createTileCallback($callback, $name, $callNum++));
		}
	}

	/** Show your personal startpage */
	#[NCA\HandlesCommand("start")]
	#[NCA\Help\Group("start")]
	public function startCommand(CmdContext $context): void {
		$this->showStartpage($context->char->name, $context, true);
	}

	/** Show the current startpage layout */
	#[NCA\HandlesCommand("startpage")]
	#[NCA\Help\Group("start")]
	#[NCA\Help\Epilogue(
		"To customize the message that is sent with the startpage:\n".
		"<tab><a href='chatcmd:///tell <myname> settings change startpage_startmsg'><symbol>settings change startpage_startmsg</a>"
	)]
	public function startpageCommand(CmdContext $context): void {
		$this->showStartpageLayout($context, false);
	}

	/** Pick an entry for the startpage at position &lt;pos&gt; (0 being the top) */
	#[NCA\HandlesCommand("startpage")]
	#[NCA\Help\Group("start")]
	public function startpagePickCommand(CmdContext $context, #[NCA\Str("pick")] string $action, int $pos): void {
		$tiles = $this->getActiveLayout();
		$unusedTiles = $this->getTiles();
		foreach ($tiles as $name => $tile) {
			unset($unusedTiles[$name]);
		}
		$blobLines = [];
		ksort($unusedTiles);
		foreach ($unusedTiles as $name => $tile) {
			$pickLink = $this->text->makeChatcmd("pick this", "/tell <myname> startpage pick {$pos} {$name}");
			$line = "<header2>{$name} [{$pickLink}]<end>\n".
				"<tab>" . implode("\n<tab>", explode("\n", $tile->description)) . "\n";
			if (isset($tile->example)) {
				$line .= "\n<tab>| " . implode("\n<tab>| ", explode("\n", $tile->example)) . "\n";
			}
			$blobLines []= $line;
		}
		if (empty($blobLines)) {
			$context->reply("You already assigned positions to all tiles.\n");
			return;
		}
		$blob = join("\n", $blobLines);
		$msg = $this->text->makeBlob("Pick a tile to insert", $blob);
		$context->reply($msg);
	}

	/** Show the description of a tile */
	#[NCA\HandlesCommand("startpage")]
	#[NCA\Help\Group("start")]
	public function startpageDescribeTileCommand(CmdContext $context, #[NCA\Str("describe")] string $action, string $tileName): void {
		$allTiles = $this->getTiles();
		$tile = $allTiles[$tileName] ?? null;
		if (!isset($tile)) {
			$context->reply("There is no tile <highlight>{$tileName}<end>.");
			return;
		}
		$msg = "<highlight>{$tileName}<end>: " . str_replace("\n", " ", $tile->description);
		$context->reply($msg);
	}

	/** Assign &lt;tile name&gt; to position &lt;pos&gt; of the startpage */
	#[NCA\HandlesCommand("startpage")]
	#[NCA\Help\Group("start")]
	public function startpagePickTileCommand(
		CmdContext $context,
		#[NCA\Str("pick")] string $action,
		int $pos,
		string $tileName
	): void {
		$currentTiles = $this->getActiveLayout();
		if (isset($currentTiles[$tileName])) {
			$context->reply("You already assigned a position to this tile.");
			return;
		}
		$allTiles = $this->getTiles();
		if (!isset($allTiles[$tileName])) {
			$context->reply("There is no tile <highlight>{$tileName}<end>.");
			return;
		}
		$tileKeys = array_keys($currentTiles);
		array_splice($tileKeys, $pos, 0, $tileName);
		$this->setTiles(...$tileKeys);
		$this->showStartpageLayout($context, true);
	}

	/** Move tiles around on the startpage */
	#[NCA\HandlesCommand("startpage")]
	#[NCA\Help\Group("start")]
	public function startpageMoveTileCommand(
		CmdContext $context,
		#[NCA\Str("move")] string $action,
		string $tileName,
		#[NCA\StrChoice("up", "down")] string $direction
	): void {
		$currentTiles = $this->getActiveLayout();
		if (!isset($currentTiles[$tileName])) {
			$context->reply("<highlight>{$tileName}<end> is currently not on your startpage.");
			return;
		}
		$delta = (strtolower($direction) === "up") ? -1 : 1;
		$tileKeys = array_keys($currentTiles);
		$oldPos = array_search($tileName, $tileKeys);
		if ($oldPos === false) {
			$context->reply("<highlight>{$tileName}<end> is currently not on your startpage.");
			return;
		}
		$newPos = $oldPos + $delta;
		if ($newPos < 0 || $newPos >= count($tileKeys)) {
			$context->reply("Cannot move <highlight>{$tileName}<end> further.");
			return;
		}
		$toMove = $tileKeys[$newPos];
		$tileKeys[$newPos] = $tileName;
		$tileKeys[$oldPos] = $toMove;
		$this->setTiles(...$tileKeys);

		$this->showStartpageLayout($context, true);
	}

	/** Remove a tile from the startpage */
	#[NCA\HandlesCommand("startpage")]
	#[NCA\Help\Group("start")]
	public function startpageRemTileCommand(CmdContext $context, PRemove $action, string $tileName): void {
		$currentTiles = $this->getActiveLayout();
		if (!isset($currentTiles[$tileName])) {
			$context->reply("<highlight>{$tileName}<end> is currently not used.");
			return;
		}
		unset($currentTiles[$tileName]);
		$tileKeys = array_keys($currentTiles);
		$this->setTiles(...$tileKeys);
		$this->showStartpageLayout($context, true);
	}

	/** List all news tiles */
	#[
		NCA\Api("/startpage/tiles"),
		NCA\GET,
		NCA\AccessLevelFrom("startpage"),
		NCA\ApiResult(code: 200, class: "NewsTile[]", desc: "List of all news items")
	]
	public function apiListTilesEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$tiles = array_values($this->getTiles());
		$result = [];
		foreach ($tiles as $tile) {
			$newTile = clone $tile;
			if (isset($tile->example)) {
				$newTile->example = $this->webChatConverter->convertMessage($tile->example);
			}
			$result []= $newTile;
		}
		return new ApiResponse($result);
	}

	/** Get the currently configured startpage layout */
	#[
		NCA\Api("/startpage/layout"),
		NCA\GET,
		NCA\AccessLevelFrom("startpage"),
		NCA\ApiResult(code: 200, class: "string[]", desc: "The order of the tiles")
	]
	public function apiGetStartpageLayoutEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_keys($this->getActiveLayout()));
	}

	/** List all news tiles */
	#[
		NCA\Api("/startpage/layout"),
		NCA\PUT,
		NCA\AccessLevelFrom("startpage"),
		NCA\RequestBody(class: "string[]", desc: "The new order for the tiles", required: true),
		NCA\ApiResult(code: 204, desc: "New layout saved")
	]
	public function apiSetStartpageLayoutEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$tiles = $request->decodedBody;
		if (!is_array($tiles)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		try {
			$this->setTiles(...$tiles);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY, [], $e->getMessage());
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Parse a method for NewsTile annotations and add the tiles
	 *
	 * @param object           $instance The object instance we're processing
	 * @param ReflectionMethod $method   The method we're scanning
	 */
	protected function parseRefMethod(object $instance, ReflectionMethod $method): void {
		$newsTileAttrs = $method->getAttributes(NCA\NewsTile::class);
		if (empty($newsTileAttrs)) {
			return;
		}
		$className = get_class($instance);
		$funcName = "{$className}::" . $method->getName() . "()";

		/** @var NCA\NewsTile */
		$attrObj = $newsTileAttrs[0]->newInstance();
		$name = $attrObj->name;
		$closure = $method->getClosure($instance);
		if (!isset($closure)) {
			throw new InvalidArgumentException(
				"{$funcName} cannot be made into a closure."
			);
		}
		$tile = new NewsTile($name, $closure);
		$tile->description = $attrObj->description;
		$tile->example = $attrObj->example;
		$this->registerNewsTile($tile);
	}

	/**
	 * Creates a CommandReply object that sens mass tells
	 *
	 * @param string $receiver The character to send the mass tells to
	 */
	protected function getMassTell(string $receiver): CommandReply {
		$sendto = new class () implements CommandReply {
			public Nadybot $chatBot;
			public string $receiver;

			public function reply($msg): void {
				$this->chatBot->sendMassTell($msg, $this->receiver);
			}
		};
		$sendto->chatBot = $this->chatBot;
		$sendto->receiver = $receiver;
		return $sendto;
	}

	protected function createTileCallback(callable $callback, string $name, int $numCall): Closure {
		return function (?string $text) use ($callback, $numCall, $name): void {
			$callback($numCall, $text, $name);
		};
	}

	protected function showStartpageLayout(CmdContext $context, bool $changed): void {
		$tiles = $this->getActiveLayout();
		if (empty($tiles)) {
			$context->reply("The current startpage is empty. Use <highlight><symbol>startpage pick 0<end> to get started.");
			return;
		}
		$blob = $this->renderLayout($tiles);
		if ($changed) {
			$msg = $this->text->makeBlob("New startpage layout", $blob);
		} else {
			$msg = $this->text->makeBlob("Current startpage layout", $blob);
		}
		$context->reply($msg);
	}

	protected function getInsertLine(int $num): string {
		return "<tab><black>0 - <end>[".
			$this->text->makeChatcmd("insert new", "/tell <myname> startpage pick {$num}").
			"]\n";
	}

	/** @param array<string,NewsTile> $tiles */
	protected function renderLayout(array $tiles): string {
		$blobLines = [];
		$i = 0;
		foreach ($tiles as $name => $tile) {
			$blobLines []= $this->getInsertLine($i);
			$descrLink = $this->text->makeChatcmd("details", "/tell <myname> startpage describe {$name}");
			$remLink = $this->text->makeChatcmd("delete", "/tell <myname> startpage rem {$name}");
			$moveUpLink = "<black>[up]<end>";
			$moveDownLink = "<black>[down]<end>";
			if ($i > 0) {
				$moveUpLink = "[" . $this->text->makeChatcmd("up", "/tell <myname> startpage move {$name} up") . "]";
			}
			if ($i < count($tiles) - 1) {
				$moveDownLink = "[" . $this->text->makeChatcmd("down", "/tell <myname> startpage move {$name} down") . "]";
			}
			$line = "<tab>" . $this->text->alignNumber($i+1, strlen((string)count($tiles))).
				" - {$moveUpLink} {$moveDownLink}   <highlight>{$name}<end> [{$descrLink}] [{$remLink}]\n";
			$blobLines []= $line;
			$i++;
		}
		$blobLines []= $this->getInsertLine($i);
		$intro = "This is the order in which the individual tiles will be displayed.\n".
			"You can move the tiles up and down to re-arrange them, or you can\n".
			"delete those which you no longer want.\n".
			"Use [insert new] to add a new tile at the chosen position.";
		return "{$intro}\n\n".
			"<header2>Your layout<end>\n\n".
			join("\n", $blobLines). "\n\n\n".
			"[" . $this->text->makeChatcmd("preview", "/tell <myname> start") . "]";
	}
}
