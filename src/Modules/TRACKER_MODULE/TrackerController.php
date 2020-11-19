<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\{
	BuddylistManager,
	CommandReply,
	DB,
	Event,
	EventManager,
	Modules\DISCORD\DiscordController,
	Nadybot,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Modules\TOWER_MODULE\TrackerEvent;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'track',
 *		accessLevel = 'all',
 *		description = 'Show and manage tracked players',
 *		help        = 'track.txt'
 *	)
 *	@ProvidesEvent("tracker(logon)")
 *	@ProvidesEvent("tracker(logoff)")
 */
class TrackerController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public DiscordController $discordController;
	
	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public PlayerManager $playerManager;
	
	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'tracked_users');
		$this->db->loadSQLFile($this->moduleName, 'tracking');
		
		$this->settingManager->add(
			$this->moduleName,
			'show_tracker_events',
			'Where to show tracker events',
			'edit',
			'options',
			'0',
			'none;priv;org;priv+org;discord;discord+priv;discord+org;discord+priv+org',
			'0;1;2;3;4;5;6;7',
			'mod',
		);
		$this->settingManager->add(
			$this->moduleName,
			'tracker_layout',
			'How to show if a tracked person logs on/off',
			'edit',
			'options',
			'0',
			'TRACK: "info" logged on/off.;+/- "info"',
			'0;1',
			'mod',
		);
		$this->settingManager->add(
			$this->moduleName,
			'tracker_use_faction_color',
			"Use faction color for the name of the tracked person",
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'mod',
		);
		$this->settingManager->add(
			$this->moduleName,
			'tracker_show_level',
			"Show the tracked person's level",
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'mod',
		);
		$this->settingManager->add(
			$this->moduleName,
			'tracker_show_prof',
			"Show the tracked person's profession",
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'mod',
		);
		$this->settingManager->add(
			$this->moduleName,
			'tracker_show_org',
			"Show the tracked person's org",
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'mod',
		);
	}
	
	/**
	 * @Event("connect")
	 * @Description("Adds all players on the track list to the buddy list")
	 */
	public function trackedUsersConnectEvent(Event $eventObj): void {
		$sql = "SELECT name FROM tracked_users_<myname>";
		/** @var TrackedUser[] */
		$data = $this->db->fetchAll(TrackedUser::class, $sql);
		foreach ($data as $row) {
			$this->buddylistManager->add($row->name, 'tracking');
		}
	}
	
	/**
	 * @Event("logOn")
	 * @Description("Records a tracked user logging on")
	 */
	public function trackLogonEvent(Event $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		/** @var TrackedUser[] */
		$data = $this->db->fetchAll(
			TrackedUser::class,
			"SELECT * FROM tracked_users_<myname> WHERE uid = ?",
			$uid
		);
		if (count($data) === 0) {
			return;
		}
		$this->db->exec(
			"INSERT INTO tracking_<myname> (uid, dt, event) ".
			"VALUES (?, ?, ?)",
			$uid,
			time(),
			'logon'
		);
		
		$msg = $this->getLogonMessage($eventObj->sender);
		
		if ($this->settingManager->getInt('show_tracker_events') & 1) {
			$this->chatBot->sendPrivate($msg, true);
		}
		if ($this->settingManager->getInt('show_tracker_events') & 2) {
			$this->chatBot->sendGuild($msg, true);
		}
		if ($this->settingManager->getInt('show_tracker_events') & 4) {
			$this->discordController->sendDiscord($msg);
		}
		$event = new TrackerEvent();
		$event->player = $eventObj->sender;
		$event->type = "tracker(logon)";
		$this->eventManager->fireEvent($event);
	}

	public function getTrackerLayout(bool $online): string {
		$style = $this->settingManager->getInt('tracker_layout');
		$color = $online ? "<green>" : "<red>";
		switch ($style) {
			case 0:
				return "TRACK: %s logged {$color}" . ($online ? "on" : "off") . "<end>.";
			case 1:
				return "{$color}" . ($online ? "+" : "-") . "<end> %s";
		}
	}

	public function getLogonMessage(string $player): string {
		$format = $this->getTrackerLayout(true);
		$whois = $this->playerManager->getByName($player);
		$info = "";
		if ($whois === null) {
			$info = "<highlight>{$player}<end>";
			return sprintf($format, $info);
		}
		$faction = strtolower($whois->faction);
		if ($this->settingManager->getBool('tracker_use_faction_color')) {
			$info = "<{$faction}>{$player}<end>";
		} else {
			$info = "<highlight>{$player}<end>";
		}
		$bracketed = [];
		$showLevel = $this->settingManager->getBool('tracker_show_level');
		$showProf = $this->settingManager->getBool('tracker_show_prof');
		$showOrg = $this->settingManager->getBool('tracker_show_org');
		if ($showLevel) {
			$bracketed []= "<highlight>{$whois->level}<end>/<green>{$whois->ai_level}<end>";
		}
		if ($showProf) {
			$bracketed []= $whois->profession;
		}
		if (count($bracketed)) {
			$info .= " (" . join(", ", $bracketed) . ")";
		} elseif ($showOrg) {
			$info .= ", ";
		}
		if ($showOrg && $whois->guild !== null && strlen($whois->guild)) {
			$info .= " <{$faction}>{$whois->guild}<end>";
		}
		return sprintf($format, $info);
	}
	
	/**
	 * @Event("logOff")
	 * @Description("Records a tracked user logging off")
	 */
	public function trackLogoffEvent(Event $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		$data = $this->db->fetchAll(
			TrackedUser::class,
			"SELECT * FROM tracked_users_<myname> WHERE uid = ?",
			$uid
		);
		if (count($data) === 0) {
			return;
		}
		$this->db->exec(
			"INSERT INTO tracking_<myname> (uid, dt, event) ".
			"VALUES (?, ?, ?)",
			$uid,
			time(),
			'logoff'
		);
		
		$msg = $this->getLogoffMessage($eventObj->sender);
		
		if ($this->settingManager->getInt('show_tracker_events') & 1) {
			$this->chatBot->sendPrivate($msg, true);
		}
		if ($this->settingManager->getInt('show_tracker_events') & 2) {
			$this->chatBot->sendGuild($msg, true);
		}
		if ($this->settingManager->getInt('show_tracker_events') & 4) {
			$this->discordController->sendDiscord($msg);
		}
		$event = new TrackerEvent();
		$event->player = $eventObj->sender;
		$event->type = "tracker(logoff)";
		$this->eventManager->fireEvent($event);
	}

	public function getLogoffMessage(string $player): string {
		$format = $this->getTrackerLayout(false);
		$whois = $this->playerManager->getByName($player);
		if ($whois === null || !$this->settingManager->getBool('tracker_use_faction_color')) {
			$info = "<highlight>{$player}<end>";
		} else {
			$faction = strtolower($whois->faction);
			$info = "<{$faction}>{$player}<end>";
		}
		return sprintf($format, $info);
	}

	/**
	 * @HandlesCommand("track")
	 * @Matches("/^track$/i")
	 */
	public function trackListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var TrackedUser[] */
		$users = $this->db->fetchAll(
			TrackedUser::class,
			"SELECT * FROM tracked_users_<myname> ORDER BY `name`"
		);
		$numrows = count($users);
		if ($numrows === 0) {
			$msg = "No characters are on the track list.";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Tracked players<end>\n";
		foreach ($users as $user) {
			/** @var ?Tracking */
			$lastState = $this->db->fetch(
				Tracking::class,
				"SELECT * FROM tracking_<myname> ".
				"WHERE `uid` = ? ORDER BY `dt` DESC LIMIT 1",
				$user->uid
			);
			$lastAction = '';
			if ($lastState !== null) {
				$lastAction = " " . $this->util->date($lastState->dt);
			}

			if ($lastState->event == 'logon') {
				$status = "<green>logon<end>";
			} elseif ($lastState->event == 'logoff') {
				$status = "<orange>logoff<end>";
			} else {
				$status = "<grey>None<end>";
			}

			$remove = $this->text->makeChatcmd('Remove', "/tell <myname> track rem $user->name");

			$history = $this->text->makeChatcmd('History', "/tell <myname> track $user->name");

			$blob .= "<tab><highlight>{$user->name}<end> ({$status}{$lastAction}) - [{$remove}] [$history]\n";
		}

		$msg = $this->text->makeBlob("Tracklist ({$numrows})", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("track")
	 * @Matches("/^track rem (.+)$/i")
	 */
	public function trackRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$uid = $this->chatBot->get_uid($name);

		if (!$uid) {
			$msg = "Character <highlight>$name<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		$data = $this->db->fetchAll(
			TrackedUser::class,
			"SELECT * FROM tracked_users_<myname> WHERE `uid` = ?",
			$uid
		);
		if (count($data) === 0) {
			$msg = "Character <highlight>$name<end> is not on the track list.";
			$sendto->reply($msg);
			return;
		}
		$this->db->exec("DELETE FROM tracked_users_<myname> WHERE `uid` = ?", $uid);
		$msg = "Character <highlight>$name<end> has been removed from the track list.";
		$this->buddylistManager->remove($name, 'tracking');

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("track")
	 * @Matches("/^track add (.+)$/i")
	 */
	public function trackAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$uid = $this->chatBot->get_uid($name);

		if (!$uid) {
			$msg = "Character <highlight>$name<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		/** @var TrackedUser[] */
		$data = $this->db->fetchAll(
			TrackedUser::class,
			"SELECT * FROM tracked_users_<myname> WHERE `uid` = ?",
			$uid
		);
		if (count($data) != 0) {
			$msg = "Character <highlight>$name<end> is already on the track list.";
			$sendto->reply($msg);
			return;
		}
		$this->db->exec(
			"INSERT INTO tracked_users_<myname> ".
			"(`name`, `uid`, `added_by`, `added_dt`) ".
			"VALUES (?, ?, ?, ?)",
			$name,
			$uid,
			$sender,
			time()
		);
		$msg = "Character <highlight>$name<end> has been added to the track list.";
		$this->buddylistManager->add($name, 'tracking');

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("track")
	 * @Matches("/^track (.+)$/i")
	 */
	public function trackShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$uid = $this->chatBot->get_uid($name);

		if (!$uid) {
			$msg = "Character <highlight>$name<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		$events = $this->db->fetchAll(
			Tracking::class,
			"SELECT `event`, `dt` FROM tracking_<myname> ".
			"WHERE `uid` = $uid ORDER BY `dt` DESC"
		);
		if (count($events) === 0) {
			$msg = "Character <highlight>$name<end> has never logged on or is not being tracked.";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>All events for {$name}<end>\n";
		foreach ($events as $event) {
			if ($event->event == 'logon') {
				$status = "<green>logon<end>";
			} elseif ($event->event == 'logoff') {
				$status = "<orange>logoff<end>";
			} else {
				$status = "<grey>unknown<end>";
			}
			$blob .= "<tab> $status - " . $this->util->date($event->dt) ."\n";
		}

		$msg = $this->text->makeBlob("Track History for $name", $blob);
		$sendto->reply($msg);
	}
}
