<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	DB,
	Event,
	EventManager,
	MessageEmitter,
	MessageHub,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Nadybot,
	Registry,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\StatsController;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "cloak",
		accessLevel: "guild",
		description: "Show the status of the city cloak",
		alias: "city"
	),

	NCA\ProvidesEvent("cloak(raise)"),
	NCA\ProvidesEvent("cloak(lower)")
]
class CloakController extends ModuleInstance implements MessageEmitter {
	public const DB_TABLE = "org_city_<myname>";

	/** Show cloak status to players at logon */
	#[NCA\Setting\Options(options: [
		'Never' => 0,
		'When cloak is down' => 1,
		'Always' => 2,
	])]
	public int $showcloakstatus = 1;

	/** How often to spam guild channel when cloak is down */
	#[NCA\Setting\Time(options: ["2m", "5m", "10m", "15m", "20m"])]
	public int $cloakReminderInterval = 300; // 5m

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private CityWaveController $cityWaveController;

	#[NCA\Inject]
	private StatsController $statsController;

	private ?int $lastReminderSent = null;

	#[NCA\Setup]
	public function setup(): void {
		$this->messageHub->registerMessageEmitter($this);
		$cloakStats = new CloakStatsCollector();
		Registry::injectDependencies($cloakStats);
		$this->statsController->registerProvider($cloakStats, "states");
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(cloak)";
	}

	/**
	 * Show the current status of the city cloak
	 *
	 * An asterisk (*) will appear next to the person's name if they manually set the cloak to on.
	 */
	#[NCA\HandlesCommand("cloak")]
	public function cloakCommand(CmdContext $context): void {
		/** @var Collection<OrgCity> */
		$data = $this->db->table(self::DB_TABLE)
			->whereIn("action", ["on", "off"])
			->orderByDesc("time")
			->limit(20)
			->asObj(OrgCity::class);
		if ($data->count() === 0) {
			$msg = "Unknown status on cloak!";
			$context->reply($msg);
			return;
		}

		/** @var OrgCity $row */
		$row = $data->shift();
		$timeSinceChange = time() - $row->time;
		$timeString = $this->util->unixtimeToReadable(3600 - $timeSinceChange, false);

		if ($timeSinceChange >= 3600 && $row->action === "off") {
			$msg = "The cloaking device is <off>disabled<end>. It is possible to enable it.";
		} elseif ($timeSinceChange < 3600 && $row->action === "off") {
			$msg = "The cloaking device is <off>disabled<end>. It is possible in {$timeString} to enable it.";
		} elseif ($timeSinceChange >= 3600 && $row->action === "on") {
			$msg = "The cloaking device is <on>enabled<end>. It is possible to disable it.";
		} elseif ($timeSinceChange < 3600 && $row->action === "on") {
			$msg = "The cloaking device is <on>enabled<end>. It is possible in {$timeString} to disable it.";
		} else {
			$msg = "The cloaking device is in an unknown state.";
		}

		$list = "Time: <highlight>" . $this->util->date($row->time) . "<end>\n";
		$list .= "Action: <highlight>Cloaking device turned " . $row->action . "<end>\n";
		$list .= "Character: <highlight>" . $row->player . "<end>\n\n";

		foreach ($data as $row) {
			$list .= "Time: <highlight>" . $this->util->date($row->time) . "<end>\n";
			$list .= "Action: <highlight>Cloaking device turned " . $row->action . "<end>\n";
			$list .= "Character: <highlight>" . $row->player . "<end>\n\n";
		}
		$blob = (array)$this->text->makeBlob("Cloak History", $list);
		foreach ($blob as &$page) {
			$page = "{$msg} {$page}";
		}
		$context->reply($blob);
	}

	/** Manually set the cloak status to on (in case the bot was offline when it was raised) */
	#[NCA\HandlesCommand("cloak")]
	public function cloakRaiseCommand(CmdContext $context, #[NCA\Str("raise", "on")] string $action): void {
		/** @var ?OrgCity */
		$row = $this->getLastOrgEntry(true);

		if ($row !== null && $row->action === "on") {
			$msg = "The cloaking device is already <on>enabled<end>.";
		} else {
			$this->db->table(self::DB_TABLE)
				->insert([
					"time" => time(),
					"action" => "on",
					"player" => "{$context->char->name}*",
				]);
			$msg = "The cloaking device has been manually enabled in the bot (you must still enable the cloak if it's disabled).";
		}

		$context->reply($msg);
		$event = new CloakEvent();
		$event->type = "cloak(raise)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: "guild",
		description: "Records when the cloak is raised or lowered"
	)]
	public function recordCloakChangesEvent(AOChatEvent $eventObj): void {
		if ($this->util->isValidSender($eventObj->sender)
			|| !preg_match("/^(.+) turned the cloaking device in your city (on|off).$/i", $eventObj->message, $arr)
		) {
			return;
		}
		$this->db->table(self::DB_TABLE)
			->insert([
				"time" => time(),
				"action" => $arr[2],
				"player" => $arr[1],
			]);
		$event = new CloakEvent();
		$event->type = $arr[2] === 'on' ? "cloak(raise)" : "cloak(lower)";
		$event->player = $arr[1];
		$this->eventManager->fireEvent($event);
	}

	public function getLastOrgEntry(bool $cloakOnly=false): ?OrgCity {
		$query = $this->db->table(self::DB_TABLE)
			->orderByDesc("time")
			->limit(1);
		if ($cloakOnly) {
			$query->whereIn("action", ["on", "off"]);
		}
		return $query->asObj(OrgCity::class)->first();
	}

	public function sendCloakMessage(string $message): void {
		$e = new RoutableMessage($message);
		$e->prependPath(new Source(
			Source::SYSTEM,
			"cloak"
		));
		$this->messageHub->handle($e);
	}

	#[NCA\Event(
		name: "timer(1min)",
		description: "Checks timer to see if cloak can be raised or lowered"
	)]
	public function checkTimerEvent(Event $eventObj): void {
		$row = $this->getLastOrgEntry();
		if ($row === null) {
			return;
		}
		$timeSinceChange = time() - $row->time;
		if ($row->action === "off") {
			// send message to org chat every 5 minutes that the cloaking device is
			// disabled past the the time that the cloaking device could be enabled.
			$interval = $this->cloakReminderInterval;
			$canSendReminder = !isset($this->lastReminderSent)
				|| (time() - $this->lastReminderSent) > $interval;
			if ($timeSinceChange >= 60*60 && $canSendReminder) {
				$timeString = $this->util->unixtimeToReadable(time() - $row->time, false);
				$this->sendCloakMessage("The cloaking device was disabled by <highlight>{$row->player}<end> {$timeString} ago. It is possible to enable it.");
				$this->lastReminderSent = time();
			}
		} elseif ($row->action === "on") {
			if ($timeSinceChange >= 60*60 && $timeSinceChange < 61*60) {
				$this->sendCloakMessage("The cloaking device was enabled one hour ago. Alien attacks can now be initiated.");
			}
		}
	}

	#[NCA\Event(
		name: "timer(1min)",
		description: "Reminds the player who lowered cloak to raise it"
	)]
	public function cloakReminderEvent(Event $eventObj): void {
		$row = $this->getLastOrgEntry(true);
		if ($row === null || $row->action === "on") {
			return;
		}

		$timeSinceChange = time() - $row->time;
		$timeString = $this->util->unixtimeToReadable(3600 - $timeSinceChange, false);

		$msg = null;
		// 10 minutes before, send tell to player
		if ($timeSinceChange >= 49*60 && $timeSinceChange <= 50*60) {
			$msg = "The cloaking device is <off>disabled<end>. It is possible in {$timeString} to enable it.";
		} elseif ($timeSinceChange >= 58*60 && $timeSinceChange <= 59*60) {
			// 1 minute before send tell to player
			$msg = "The cloaking device is <off>disabled<end>. It is possible in {$timeString} to enable it.";
			// @phpstan-ignore-next-line
		} elseif ($timeSinceChange >= 59*60 && ($timeSinceChange % (60*5) >= 0 && $timeSinceChange % (60*5) <= 60)) {
			// when cloak can be raised, send tell to player and
			// every 5 minutes after, send tell to player
			$msg = "The cloaking device is <off>disabled<end>. Please enable it now.";
		} else {
			return;
		}

		// send message to all online alts
		$altInfo = $this->altsController->getAltInfo($row->player);
		foreach ($altInfo->getOnlineAlts() as $name) {
			$this->chatBot->sendMassTell($msg, $name);
		}
	}

	#[NCA\Event(
		name: "logOn",
		description: "Show cloak status to guild members logging in"
	)]
	public function cityGuildLogonEvent(UserStateEvent $eventObj): void {
		if (!$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$eventObj->sender])
			|| !is_string($eventObj->sender)
			|| $eventObj->wasOnline !== false
		) {
			return;
		}

		$data = $this->getCloakStatus();
		if (!isset($data)) {
			return;
		}
		[$case, $msg] = $data;

		if ($case <= $this->showcloakstatus) {
			$this->chatBot->sendMassTell($msg, $eventObj->sender);
		}
	}

	#[
		NCA\NewsTile(
			name: "cloak-status",
			description: "Shows the current status of the city cloak, if and when\n".
				"new raids can be initiated",
			example: "<header2>City<end>\n".
				"<tab>The cloaking device is <on>enabled<end>. It is possible to disable it."
		)
	]
	public function cloakStatusTile(string $sender, callable $callback): void {
		$data = $this->getCloakStatus();
		if (!isset($data)) {
			$callback(null);
			return;
		}
		[$case, $msg] = $data;
		$msg = "<header2>City<end>\n<tab>{$msg}";
		$callback($msg);
	}

	/**
	 * @return null|array<int|string>
	 *
	 * @psalm-return null|array{0:int,1:string}
	 *
	 * @phpstan-return null|array{0:int,1:string}
	 */
	protected function getCloakStatus(): ?array {
		$row = $this->getLastOrgEntry(true);

		if ($row === null) {
			return null;
		}
		$timeSinceChange = time() - $row->time;
		$timeString = $this->util->unixtimeToReadable(3600 - $timeSinceChange, false);

		if ($timeSinceChange >= 60*60 && $row->action === "off") {
			return [1, "The cloaking device is <off>disabled<end>. ".
				"It is possible to enable it.", ];
		} elseif ($timeSinceChange < 60*30 && $row->action === "off") {
			$msg = "RAID IN PROGRESS, <red>DO NOT ENTER CITY!<end>";
			$wave = $this->cityWaveController->getWave();
			if ($wave === 9) {
				$msg .= " - Waiting for <highlight>General<end>.";
			} elseif (isset($wave)) {
				$msg .= " - Waiting for <highlight>wave {$wave}<end>.";
			}
			return [1, $msg];
		} elseif ($timeSinceChange < 60*60 && $row->action === "off") {
			return [1, "Cloaking device is <off>disabled<end>. ".
				"It is possible in <highlight>{$timeString}<end> to enable it.", ];
		} elseif ($timeSinceChange >= 60*60 && $row->action === "on") {
			return [2, "The cloaking device is <on>enabled<end>. ".
				"It is possible to disable it.", ];
		} elseif ($timeSinceChange < 60*60 && $row->action === "on") {
			return [2, "The cloaking device is <on>enabled<end>. ".
				"It is possible in <highlight>{$timeString}<end> to disable it.", ];
		}
		return [1, "Unknown status on city cloak!"];
	}
}
