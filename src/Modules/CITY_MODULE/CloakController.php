<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Event,
	EventManager,
	Modules\ALTS\AltsController,
	Nadybot,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'cloak',
 *		accessLevel = 'guild',
 *		description = 'Show the status of the city cloak',
 *		help        = 'cloak.txt',
 *		alias		= 'city'
 *	)
 *	@ProvidesEvent("cloak(raise)")
 *	@ProvidesEvent("cloak(lower)")
 */
class CloakController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public AltsController $altsController;
	
	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'org_city');
	
		$this->settingManager->add(
			$this->moduleName,
			"showcloakstatus",
			"Show cloak status to players at logon",
			"edit",
			"options",
			"1",
			"Never;When cloak is down;Always",
			"0;1;2"
		);
		$this->settingManager->add(
			$this->moduleName,
			"cloak_reminder_interval",
			"How often to spam guild channel when cloak is down",
			"edit",
			"time",
			"5m",
			"2m;5m;10m;15m;20m"
		);
	}
	
	/**
	 * @HandlesCommand("cloak")
	 * @Matches("/^cloak$/i")
	 */
	public function cloakCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var OrgCity[] */
		$data = $this->db->fetchAll(
			OrgCity::class,
			"SELECT * FROM org_city_<myname> ".
			"WHERE `action` = 'on' OR `action` = 'off' ".
			"ORDER BY `time` DESC LIMIT 20"
		);
		if (count($data) === 0) {
			$msg = "Unknown status on cloak!";
			$sendto->reply($msg);
			return;
		}
		/** @var OrgCity $row */
		$row = array_shift($data);
		$timeSinceChange = time() - $row->time;
		$timeString = $this->util->unixtimeToReadable(3600 - $timeSinceChange, false);

		if ($timeSinceChange >= 3600 && $row->action === "off") {
			$msg = "The cloaking device is <orange>disabled<end>. It is possible to enable it.";
		} elseif ($timeSinceChange < 3600 && $row->action === "off") {
			$msg = "The cloaking device is <orange>disabled<end>. It is possible in $timeString to enable it.";
		} elseif ($timeSinceChange >= 3600 && $row->action === "on") {
			$msg = "The cloaking device is <green>enabled<end>. It is possible to disable it.";
		} elseif ($timeSinceChange < 3600 && $row->action === "on") {
			$msg = "The cloaking device is <green>enabled<end>. It is possible in $timeString to disable it.";
		}

		$list = "Time: <highlight>" . $this->util->date($row->time) . "<end>\n";
		$list .= "Action: <highlight>Cloaking device turned " . $row->action . "<end>\n";
		$list .= "Character: <highlight>" . $row->player . "<end>\n\n";

		foreach ($data as $row) {
			$list .= "Time: <highlight>" . $this->util->date($row->time) . "<end>\n";
			$list .= "Action: <highlight>Cloaking device turned " . $row->action . "<end>\n";
			$list .= "Character: <highlight>" . $row->player . "<end>\n\n";
		}
		$msg .= " " . $this->text->makeBlob("Cloak History", $list);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("cloak")
	 * @Matches("/^cloak (raise|on)$/i")
	 */
	public function cloakRaiseCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var ?OrgCity */
		$row = $this->db->fetch(
			OrgCity::class,
			"SELECT * FROM org_city_<myname> ".
			"WHERE `action` = 'on' OR `action` = 'off' ".
			"ORDER BY `time` DESC LIMIT 1"
		);

		if ($row !== null && $row->action === "on") {
			$msg = "The cloaking device is already <green>enabled<end>.";
		} else {
			$this->db->exec(
				"INSERT INTO org_city_<myname> (`time`, `action`, `player`) ".
				"VALUES (?, ?, ?)",
				time(),
				'on',
				"{$sender}*"
			);
			$msg = "The cloaking device has been manually enabled in the bot (you must still enable the cloak if it's disabled).";
		}

		$sendto->reply($msg);
		$event = new CloakEvent();
		$event->type = "cloak(raise)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);
	}
	
	/**
	 * @Event("guild")
	 * @Description("Records when the cloak is raised or lowered")
	 */
	public function recordCloakChangesEvent(Event $eventObj): void {
		if ($this->util->isValidSender($eventObj->sender)
			|| !preg_match("/^(.+) turned the cloaking device in your city (on|off).$/i", $eventObj->message, $arr)
		) {
			return;
		}
		$this->db->exec(
			"INSERT INTO org_city_<myname> (`time`, `action`, `player`) ".
			"VALUES (?, ?, ?)",
			time(),
			$arr[2],
			$arr[1]
		);
		$event = new CloakEvent();
		$event->type = $arr[2] === 'on' ? "cloak(raise)" : "cloak(lower)";
		$event->player = $arr[1];
		$this->eventManager->fireEvent($event);
	}
	
	/**
	 * @Event("timer(1min)")
	 * @Description("Checks timer to see if cloak can be raised or lowered")
	 */
	public function checkTimerEvent(Event $eventObj): void {
		/** @var ?OrgCity */
		$row = $this->db->fetch(
			OrgCity::class,
			"SELECT * FROM org_city_<myname> ORDER BY `time` DESC LIMIT 1"
		);
		if ($row === null) {
			return;
		}
		$timeSinceChange = time() - $row->time;
		if ($row->action === "off") {
			// send message to org chat every 5 minutes that the cloaking device is
			// disabled past the the time that the cloaking device could be enabled.
			$interval = $this->settingManager->getInt('cloak_reminder_interval');
			if ($timeSinceChange >= 60*60 && ($timeSinceChange % $interval >= 0 && $timeSinceChange % $interval <= 60 )) {
				$timeString = $this->util->unixtimeToReadable(time() - $row->time, false);
				$this->chatBot->sendGuild("The cloaking device was disabled by <highlight>{$row->player}<end> $timeString ago. It is possible to enable it.");
			}
		} elseif ($row->action === "on") {
			if ($timeSinceChange >= 60*60 && $timeSinceChange < 61*60) {
				$this->chatBot->sendGuild("The cloaking device was enabled one hour ago. Alien attacks can now be initiated.");
			}
		}
	}
	
	/**
	 * @Event("timer(1min)")
	 * @Description("Reminds the player who lowered cloak to raise it")
	 */
	public function cloakReminderEvent(Event $eventObj): void {
		// valid states for action are: 'on', 'off'
		/** @var ?OrgCity */
		$row = $this->db->fetch(
			OrgCity::class,
			"SELECT * FROM org_city_<myname> ".
			"WHERE `action` = 'on' OR `action` = 'off' ".
			"ORDER BY `time` DESC LIMIT 1"
		);
		if ($row === null || $row->action === "on") {
			return;
		}

		$timeSinceChange = time() - $row->time;
		$timeString = $this->util->unixtimeToReadable(3600 - $timeSinceChange, false);

		$msg = null;
		// 10 minutes before, send tell to player
		if ($timeSinceChange >= 49*60 && $timeSinceChange <= 50*60) {
			$msg = "The cloaking device is <orange>disabled<end>. It is possible in $timeString to enable it.";
		} elseif ($timeSinceChange >= 58*60 && $timeSinceChange <= 59*60) {
			// 1 minute before send tell to player
			$msg = "The cloaking device is <orange>disabled<end>. It is possible in $timeString to enable it.";
		} elseif ($timeSinceChange >= 59*60 && ($timeSinceChange % (60*5) >= 0 && $timeSinceChange % (60*5) <= 60 )) {
			// when cloak can be raised, send tell to player and
			// every 5 minutes after, send tell to player
			$msg = "The cloaking device is <orange>disabled<end>. Please enable it now.";
		} else {
			return;
		}

		// send message to all online alts
		$altInfo = $this->altsController->getAltInfo($row->player);
		foreach ($altInfo->getOnlineAlts() as $name) {
			$this->chatBot->sendMassTell($msg, $name);
		}
	}
	
	/**
	 * @Event("logOn")
	 * @Description("Show cloak status to guild members logging in")
	 */
	public function cityGuildLogonEvent(Event $eventObj): void {
		if (!$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$eventObj->sender])
		) {
			return;
		}
		/** @var ?OrgCity */
		$row = $this->db->fetch(
			OrgCity::class,
			"SELECT * FROM org_city_<myname> ".
			"WHERE `action` = 'on' OR `action` = 'off' ".
			"ORDER BY `time` DESC LIMIT 0, 20"
		);

		if ($row === null) {
			return;
		}
		$timeSinceChange = time() - $row->time;
		$timeString = $this->util->unixtimeToReadable(3600 - $timeSinceChange, false);

		$case = 0;
		if ($timeSinceChange >= 60*60 && $row->action === "off") {
			$case = 1;
			$msg = "The cloaking device is <orange>disabled<end>. It is possible to enable it.";
		} elseif ($timeSinceChange < 60*30 && $row->action === "off") {
			$case = 1;
			$msg = "<red>RAID IN PROGRESS!  DO NOT ENTER CITY!</red>";
		} elseif ($timeSinceChange < 60*60 && $row->action === "off") {
			$msg = "Cloaking device is <orange>disabled<end>. It is possible in $timeString to enable it.";
			$case = 1;
		} elseif ($timeSinceChange >= 60*60 && $row->action === "on") {
			$msg = "The cloaking device is <green>enabled<end>. It is possible to disable it.";
			$case = 2;
		} elseif ($timeSinceChange < 60*60 && $row->action === "on") {
			$msg = "The cloaking device is <green>enabled<end>. It is possible in $timeString to disable it.";
			$case = 2;
		} else {
			$msg = "Unknown status on city cloak!";
			$case = 1;
		}

		if ($case <= $this->settingManager->getInt("showcloakstatus")) {
			$this->chatBot->sendMassTell($msg, $eventObj->sender);
		}
	}
}
