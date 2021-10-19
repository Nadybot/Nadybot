<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Addendum\ReflectionAnnotatedClass;
use Addendum\ReflectionAnnotatedMethod;
use Closure;
use DateInterval;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use ReflectionMethod;
use Nadybot\Core\{
	AccessManager,
	CommandReply,
	DB,
	Event,
	Nadybot,
	Registry,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\Modules\BAN\BanController;

/**
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'startpage',
 *		accessLevel = 'mod',
 *		description = 'configures the personal startpage',
 *		help        = 'startpage.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'start',
 *		accessLevel = 'member',
 *		description = 'Shows your personal startpage',
 *		help        = 'startpage.txt'
 *	)
 */
class StartpageController {
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public BanController $banController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @var array<string,NewsTile> */
	protected array $tiles = [];

	/**
	 * Parse a method for NewsTile annotations and add the tiles
	 *
	 * @param object $instance The object instance we're processing
	 * @param \Addendum\ReflectionAnnotatedMethod $method The method we're scanning
	 */
	protected function parseRefMethod(object $instance, ReflectionAnnotatedMethod $method): void {
		if (!$method->hasAnnotation('NewsTile')) {
			return;
		}
		$className = get_class($instance);
		$funcName = "{$className}::" . $method->getName() . "()";
		$name = $method->getAnnotation("NewsTile")->value;
		if (!isset($name)) {
			throw new InvalidArgumentException(
				"{$funcName} has an invalid @NewsTile annotation."
			);
		}
		$descr = $method->getAnnotation("Description");
		if (!isset($descr) || $descr === false) {
			throw new InvalidArgumentException(
				"{$funcName} has no @Description annotation."
			);
		}
		$descr = $descr->value;
		if (!isset($descr)) {
			throw new InvalidArgumentException(
				"{$funcName} has an invalid @Description annotation."
			);
		}
		$tile = new NewsTile($name, $method->getClosure($instance));
		$tile->description = $descr;
		$this->registerNewsTile($tile);
	}

	/**
	 * @Setup
	 */
	public function setup(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$class = new ReflectionAnnotatedClass($instance);
			foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				$this->parseRefMethod($instance, $method);
			}
		}
		$this->settingManager->add(
			$this->moduleName,
			"startpage_layout",
			"The tiles to show on the startpage",
			"noedit",
			"text",
			""
		);
		$this->settingManager->add(
			$this->moduleName,
			"startpage_startmsg",
			"The message when sending the startpage to people",
			"edit",
			"text",
			"Welcome, {name}!",
			"",
			"",
			"mod",
			"startpage_startmsg.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			"startpage_show_members",
			"When to show non-org-members the startpage",
			"edit",
			"options",
			"2",
			"Do not show to non-org-members;When Logging in;When joining the private channel",
			"0;1;2",
		);
	}

	protected function getMassTell(string $receiver): CommandReply {
		$sendto = new class implements CommandReply {
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

	/**
	 * @Event("logOn")
	 * @Description("Show startpage to (org) members logging in")
	 */
	public function logonEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady()) {
			return;
		}
		if (isset($this->chatBot->guildmembers[$sender])) {
			$this->showStartpage($sender, $this->getMassTell($sender));
			return;
		}
		$uid = $this->chatBot->get_uid($sender);
		if ($uid === false) {
			return;
		}
		if ($this->settingManager->getInt("startpage_show_members") !== 1) {
			return;
		}
		if ($this->accessManager->getAccessLevelForCharacter($sender) === "all") {
			return;
		}
		$this->banController->handleBan(
			$uid,
			function (int $uid, string $sender): void {
				$this->showStartpage($sender, $this->getMassTell($sender));
			},
			null,
			$sender
		);
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Show startpage to players joining private channel")
	 */
	public function privateChannelJoinEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady() || isset($this->chatBot->guildmembers[$sender])) {
			return;
		}
		if ($this->settingManager->getInt("startpage_show_members") !== 2) {
			return;
		}
		$this->showStartpage($sender, $this->getMassTell($sender));
	}

	/**
	 * @NewsTile("time")
	 * @Description("Shows the current date and time in UTC and game")
	 */
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
			throw new InvalidArgumentException("You cannot display a news tile more than once.");
		}
		$invalid = $coll->filter(function (string $tileName): bool {
			return !isset($this->tiles[$tileName]);
		});
		if ($invalid->isNotEmpty()) {
			throw new InvalidArgumentException($invalid->first() . " is not a valid tile.");
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
		$tileString = $this->settingManager->getString("startpage_layout");
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

	protected function createTileCallback(callable $callback, int $numCall): Closure {
		return function(?string $text) use ($callback, $numCall): void {
			$callback($numCall, $text);
		};
	}

	public function getStartpageString(string $sender): string {
		$msg = $this->settingManager->getString("startpage_startmsg");
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
		$callback = function(int $numCall, ?string $text) use (&$callResults, $tiles, $sendto, $sender): void {
			$callResults[$numCall] = isset($text) ? trim($text) : null;
			if (count($callResults) < count($tiles)) {
				return;
			}
			ksort($callResults, SORT_NUMERIC);
			$dataParts = array_filter(array_values($callResults));
			if (empty($dataParts)) {
				return;
			}
			$blob = join("\n\n", $dataParts);
			$msg = $this->text->makeBlob($this->getStartpageString($sender), $blob);
			$sendto->reply($msg);
		};
		foreach ($tiles as $name => $tile) {
			$tile->call($sender, $this->createTileCallback($callback, $callNum++));
		}
	}

	/**
	 * This command handler shows one's personal startpage
	 *
	 * @HandlesCommand("start")
	 * @Matches("/^start$/i")
	 */
	public function startCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showStartpage($sender, $sendto, true);
	}

	/**
	 * This command handler shows one's personal startpage
	 *
	 * @HandlesCommand("startpage")
	 * @Matches("/^startpage$/i")
	 */
	public function startpageCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showStartpageLayout($sendto, false);
	}

	protected function showStartpageLayout(CommandReply $sendto, bool $changed): void {
		$tiles = $this->getActiveLayout();
		if (empty($tiles)) {
			$sendto->reply("The current startpage is empty. Use <highlight><symbol>startpage pick 0<end> to get started.");
			return;
		}
		$blob = $this->renderLayout($tiles);
		if ($changed) {
			$msg = $this->text->makeBlob("New startpage layout", $blob);
		} else {
			$msg = $this->text->makeBlob("Current startpage layout", $blob);
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler lets you pick an unused tile for a specific position
	 *
	 * @HandlesCommand("startpage")
	 * @Matches("/^startpage\s+pick\s+(\d+)$/i")
	 */
	public function startpagePickCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$tiles = $this->getActiveLayout();
		$unusedTiles = $this->getTiles();
		foreach ($tiles as $name => $tile) {
			unset($unusedTiles[$name]);
		}
		$blobLines = [];
		ksort($unusedTiles);
		foreach ($unusedTiles as $name => $tile) {
			$blobLines []= "<header2>{$name}<end>\n".
				"<tab>" . implode("\n<tab>", explode("\n", $tile->description)) . "\n".
				"<tab>".
				$this->text->makeChatcmd("pick this", "/tell <myname> startpage pick {$args[1]} {$name}").
				"\n";
		}
		if (empty($blobLines)) {
			$sendto->reply("You already assigned positions to all tiles.\n");
			return;
		}
		$blob = join("\n", $blobLines);
		$msg = $this->text->makeBlob("Pick a tile to insert", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows the description of a tile
	 *
	 * @HandlesCommand("startpage")
	 * @Matches("/^startpage\s+describe\s+(.+)$/i")
	 */
	public function startpageDescribeTileCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$allTiles = $this->getTiles();
		$tile = $allTiles[$args[1]] ?? null;
		if (!isset($tile)) {
			$sendto->reply("There is no tile <highlight>{$args[1]}<end>.");
			return;
		}
		$msg = "<highlight>{$args[1]}<end>: " . str_replace("\n", " ", $tile->description);
		$sendto->reply($msg);
	}

	/**
	 * This command handler assigns an unused tile to a position
	 *
	 * @HandlesCommand("startpage")
	 * @Matches("/^startpage\s+pick\s+(\d+)\s+(.+)$/i")
	 */
	public function startpagePickTileCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$currentTiles = $this->getActiveLayout();
		if (isset($currentTiles[$args[2]])) {
			$sendto->reply("You already assigned a position to this tile.");
			return;
		}
		$allTiles = $this->getTiles();
		if (!isset($allTiles[$args[2]])) {
			$sendto->reply("There is no tile <highlight>{$args[2]}<end>.");
			return;
		}
		$tileKeys = array_keys($currentTiles);
		array_splice($tileKeys, (int)$args[1], 0, $args[2]);
		$this->setTiles(...$tileKeys);
		$this->showStartpageLayout($sendto, true);
	}

	/**
	 * This command handler moves around tiles
	 *
	 * @HandlesCommand("startpage")
	 * @Matches("/^startpage\s+setpos\s+(.+)\s+(\d+)$/i")
	 */
	public function startpageSetPosCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$currentTiles = $this->getActiveLayout();
		if (!isset($currentTiles[$args[1]])) {
			$sendto->reply("<highlight>{$args[1]}<end> is currently not on your startpage.");
			return;
		}
		$tileKeys = array_keys($currentTiles);
		$oldPos = array_search($args[1], $tileKeys);
		$toMove = $tileKeys[$args[2]];
		$tileKeys[$args[2]] = $args[1];
		$tileKeys[$oldPos] = $toMove;

		$this->setTiles(...$tileKeys);
		$this->showStartpageLayout($sendto, true);
	}

	/**
	 * This command handler removes a tile from the startpage
	 *
	 * @HandlesCommand("startpage")
	 * @Matches("/^startpage\s+(?:remove|delete|rem|del|rm)\s+(.+)$/i")
	 */
	public function startpageRemTileCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$currentTiles = $this->getActiveLayout();
		if (!isset($currentTiles[$args[1]])) {
			$sendto->reply("<highlight>{$args[1]}<end> is currently not used.");
			return;
		}
		unset($currentTiles[$args[1]]);
		$tileKeys = array_keys($currentTiles);
		$this->setTiles(...$tileKeys);
		$this->showStartpageLayout($sendto, true);
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
				$upPos = $i-1;
				$moveUpLink = "[" . $this->text->makeChatcmd("up", "/tell <myname> startpage setpos {$name} {$upPos}") . "]";
			}
			if ($i < count($tiles) - 1) {
				$downPos = $i+1;
				$moveDownLink = "[" . $this->text->makeChatcmd("down", "/tell <myname> startpage setpos {$name} {$downPos}") . "]";
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
