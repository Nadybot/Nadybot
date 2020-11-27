<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\{
	BuddylistManager,
	CommandReply,
	DB,
	DBSchema\Player,
	Event,
	EventManager,
	Modules\DISCORD\DiscordController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	ONLINE_MODULE\OnlinePlayer,
};

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
	/** No grouping, just sorting */
	public const GROUP_NONE = 0;
	/** Group by title level */
	public const GROUP_TL = 1;
	/** Group by profession */
	public const GROUP_PROF = 2;

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

	/** @Inject */
	public OnlineController $onlineController;
	
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
		$this->settingManager->add(
			$this->moduleName,
			"tracker_group_by",
			"Group online list by",
			"edit",
			"options",
			"1",
			"do not group;title level;profession",
			"0;1;2"
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
	public function trackLogonEvent(UserStateEvent $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		/** @var TrackedUser[] */
		$data = $this->db->fetchAll(
			TrackedUser::class,
			"SELECT * FROM `tracked_users_<myname>` WHERE `uid` = ?",
			$uid
		);
		if (count($data) === 0) {
			return;
		}
		$this->db->exec(
			"INSERT INTO `tracking_<myname>` (`uid`, `dt`, `event`) ".
			"VALUES (?, ?, ?)",
			$uid,
			time(),
			'logon'
		);
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($eventObj): void {
				$msg = $this->getLogonMessage($player, $eventObj->sender);
				
				if ($this->settingManager->getInt('show_tracker_events') & 1) {
					$this->chatBot->sendPrivate($msg, true);
				}
				if ($this->settingManager->getInt('show_tracker_events') & 2) {
					$this->chatBot->sendGuild($msg, true);
				}
				if ($this->settingManager->getInt('show_tracker_events') & 4) {
					$this->discordController->sendDiscord($msg);
				}
			},
			$eventObj->sender
		);
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

	/**
	 * Get the message to show when a tracked player logs on
	 */
	public function getLogonMessage(?Player $player, string $name): string {
		$format = $this->getTrackerLayout(true);
		$info = "";
		if ($player === null) {
			$info = "<highlight>{$name}<end>";
			return sprintf($format, $info);
		}
		$faction = strtolower($player->faction);
		if ($this->settingManager->getBool('tracker_use_faction_color')) {
			$info = "<{$faction}>{$name}<end>";
		} else {
			$info = "<highlight>{$name}<end>";
		}
		$bracketed = [];
		$showLevel = $this->settingManager->getBool('tracker_show_level');
		$showProf = $this->settingManager->getBool('tracker_show_prof');
		$showOrg = $this->settingManager->getBool('tracker_show_org');
		if ($showLevel) {
			$bracketed []= "<highlight>{$player->level}<end>/<green>{$player->ai_level}<end>";
		}
		if ($showProf) {
			$bracketed []= $player->profession;
		}
		if (count($bracketed)) {
			$info .= " (" . join(", ", $bracketed) . ")";
		} elseif ($showOrg) {
			$info .= ", ";
		}
		if ($showOrg && $player->guild !== null && strlen($player->guild)) {
			$info .= " <{$faction}>{$player->guild}<end>";
		}
		return sprintf($format, $info);
	}
	
	/**
	 * @Event("logOff")
	 * @Description("Records a tracked user logging off")
	 */
	public function trackLogoffEvent(UserStateEvent $eventObj): void {
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
		
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($eventObj): void {
				$msg = $this->getLogoffMessage($player, $eventObj->sender);
				
				if ($this->settingManager->getInt('show_tracker_events') & 1) {
					$this->chatBot->sendPrivate($msg, true);
				}
				if ($this->settingManager->getInt('show_tracker_events') & 2) {
					$this->chatBot->sendGuild($msg, true);
				}
				if ($this->settingManager->getInt('show_tracker_events') & 4) {
					$this->discordController->sendDiscord($msg);
				}
			},
			$eventObj->sender
		);
		$event = new TrackerEvent();
		$event->player = $eventObj->sender;
		$event->type = "tracker(logoff)";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Get the message to show when a tracked player logs off
	 */
	public function getLogoffMessage(?Player $player, string $name): string {
		$format = $this->getTrackerLayout(false);
		if ($player === null || !$this->settingManager->getBool('tracker_use_faction_color')) {
			$info = "<highlight>{$name}<end>";
		} else {
			$faction = strtolower($player->faction);
			$info = "<{$faction}>{$name}<end>";
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
	 * @Matches("/^track (?:rem|del) (.+)$/i")
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
	 * @Matches("/^track online$/i")
	 */
	public function trackOnlineCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT p.*, p.`name` AS `pmain`, '' AS `afk`, TRUE as `online` ".
			"FROM `tracked_users_<myname>` tu ".
			"JOIN players p ON tu.`name` = p.`name` ".
			"ORDER BY p.name ASC";
		/** @var OnlinePlayer[] */
		$data = $this->db->fetchAll(OnlinePlayer::class, $sql);
		$data = array_filter(
			$data,
			function (OnlinePlayer $player): bool {
				return $this->buddylistManager->isOnline($player->name) === true;
			}
		);
		if (!count($data)) {
			$sendto->reply("No tracked players are currently online.");
			return;
		}
		$blob = $this->renderOnlineList($data);
		$msg = $this->text->makeBlob("Online tracked players (" . count($data). ")", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Get the blob with details about the tracked players currently online
	 * @param OnlinePlayer[] $players
	 * @return string The blob
	 */
	public function renderOnlineList(array $players): string {
		$groupBy = $this->settingManager->getInt('tracker_group_by');
		$groups = [];
		if ($groupBy === static::GROUP_TL) {
			foreach ($players as $player) {
				$tl = $this->util->levelToTL($player->level);
				$groups[$tl] ??= (object)['title' => 'TL'.$tl, 'members' => [], 'sort' => $tl];
				$groups[$tl]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_PROF) {
			foreach ($players as $player) {
				$prof = $player->profession;
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->onlineController->getProfessionId($player->profession).">";
				$groups[$prof] ??= (object)[
					'title' => $profIcon . " {$player->profession}",
					'members' => [],
					'sort' => $player->profession,
				];
				$groups[$prof]->members []= $player;
			}
		} else {
			$groups["all"] ??= (object)['title' => "All tracked players", 'members' => $players, 'sort' => 0];
		}
		usort($groups, function(object $a, object $b): int {
			return $a->sort <=> $b->sort;
		});
		$parts = [];
		foreach ($groups as $group) {
			$parts []= "<header2>{$group->title} (" . count($group->members) . ")<end>\n".
				$this->renderPlayerGroup($group->members, $groupBy);
		}

		return join("\n\n", $parts);
	}

	/**
	 * Return the content of the online list for one player group
	 * @param OnlinePlayer[] $players The list of players in that group
	 * @return string The blob for this group
	 */
	public function renderPlayerGroup(array $players, int $groupBy): string {
		return "<tab>" . join(
			"\n<tab>",
			array_map(
				function(OnlinePlayer $player) use ($groupBy) {
					return $this->renderPlayerLine($player, $groupBy);
				},
				$players
			)
		);
	}

	/**
	 * Render a single online-line of a player
	 * @param OnlinePlayer $player The player to render
	 * @param int $groupBy Which grouping method to use. When grouping by prof, we don't showthe prof icon
	 * @return string A single like without newlines
	 */
	public function renderPlayerLine(OnlinePlayer $player, int $groupBy): string {
		$faction = strtolower($player->faction);
		$blob = "";
		if ($groupBy !== static::GROUP_PROF) {
			if ($player->profession === null) {
				$blob .= "? ";
			} else {
				$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".
					$this->onlineController->getProfessionId($player->profession) . "> ";
			}
		}
		if ($this->settingManager->getBool('tracker_use_faction_color')) {
			$blob .= "<{$faction}>{$player->name}<end>";
		} else {
			$blob .= "<highlight>{$player->name}<end>";
		}
		$prof = $this->util->getProfessionAbbreviation($player->profession);
		$blob .= " ({$player->level}/<green>{$player->ai_level}<end>, {$prof})";
		if ($player->guild !== null && $player->guild !== '') {
			$blob .= " :: <{$faction}>{$player->guild}<end> ({$player->guild_rank})";
		}
		$historyLink = $this->text->makeChatcmd("history", "/tell <myname> track {$player->name}");
		$removeLink = $this->text->makeChatcmd("untrack", "/tell <myname> track rem {$player->name}");
		$blob .= " [{$removeLink}] [{$historyLink}]";
		return $blob;
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
