<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AdminManager,
	BuddylistManager,
	CommandReply,
	DB,
	DBSchema\Player,
	Event,
	EventManager,
	MessageEmitter,
	MessageHub,
	Modules\DISCORD\DiscordController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	ONLINE_MODULE\OnlinePlayer,
	PRIVATE_CHANNEL_MODULE\PrivateChannelController,
	TOWER_MODULE\TowerAttackEvent,
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
class TrackerController implements MessageEmitter {
	public const DB_TABLE = "tracked_users_<myname>";
	public const DB_TRACKING = "tracking_<myname>";

	/** No grouping, just sorting */
	public const GROUP_NONE = 0;
	/** Group by title level */
	public const GROUP_TL = 1;
	/** Group by profession */
	public const GROUP_PROF = 2;

	public const ATT_NONE = 0;
	public const ATT_OWN_ORG = 1;
	public const ATT_MEMBER_ORG = 2;
	public const ATT_CLAN = 4;
	public const ATT_OMNI = 8;
	public const ATT_NEUTRAL = 16;

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
	public MessageHub $messageHub;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public OnlineController $onlineController;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");

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
		$this->settingManager->add(
			$this->moduleName,
			"tracker_add_attackers",
			"Automatically track tower field attackers",
			"edit",
			"options",
			"0",
			"Off".
				";Attacking my own org's tower fields".
				";Attacking tower fields of bot members".
				";Attacking Clan fields".
				";Attacking Omni fields".
				";Attacking Neutral fields".
				";Attacking Non-Clan fields".
				";Attacking Non-Omni fields".
				";Attacking Non-Neutral fields".
				";All",
			"0;1;2;4;8;16;24;20;12;28"
		);
		$this->messageHub->registerMessageEmitter($this);
	}

	/**
	 * @Event("connect")
	 * @Description("Adds all players on the track list to the buddy list")
	 */
	public function trackedUsersConnectEvent(Event $eventObj): void {
		$this->db->table(self::DB_TABLE)
			->asObj(TrackedUser::class)
			->each(function(TrackedUser $row) {
				$this->buddylistManager->add($row->name, 'tracking');
			});
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(tracker)";
	}

	/**
	 * @Event("tower(attack)")
	 * @Description("Automatically track tower field attackers")
	 */
	public function trackTowerAttacks(TowerAttackEvent $eventObj): void {
		$attacker = $eventObj->attacker;
		$defGuild = $eventObj->defender->org ?? null;
		$defFaction = $eventObj->defender->faction ?? null;
		$trackWho = $this->settingManager->getInt('tracker_add_attackers');
		if ($trackWho === self::ATT_NONE) {
			return;
		}
		if ($trackWho === self::ATT_OWN_ORG ) {
			$attackingMyOrg = isset($defGuild) && $defGuild === $this->chatBot->vars["my_guild"];
			if (!$attackingMyOrg) {
				return;
			}
		}
		if ($trackWho === self::ATT_MEMBER_ORG) {
			if (!isset($defGuild)) {
				return;
			}
			$isOurGuild = $this->db->table(PrivateChannelController::DB_TABLE, "m")
				->join("players AS p", "m.name", "p.name")
				->where("p.guild", $defGuild)
				->exists() ||
			$this->db->table(AdminManager::DB_TABLE, "a")
				->join("players AS p", "a.name", "p.name")
				->where("p.guild", $defGuild)
				->exists();
			if (!$isOurGuild) {
				return;
			}
		}
		if ($trackWho >= self::ATT_CLAN) {
			if ($defFaction === "Clan" && ($trackWho & self::ATT_CLAN) === 0) {
				return;
			}
			if ($defFaction === "Omni" && ($trackWho & self::ATT_OMNI) === 0) {
				return;
			}
			if ($defFaction === "Neutral" && ($trackWho & self::ATT_NEUTRAL) === 0) {
				return;
			}
		}
		$this->trackUid($attacker->charid, $attacker->name);
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
		if ($this->db->table(self::DB_TABLE)->where("uid", $uid)->doesntExist()) {
			return;
		}
		$this->db->table(self::DB_TRACKING)
			->insert([
				"uid" => $uid,
				"dt" => time(),
				"event" => "logon",
			]);
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($eventObj): void {
				$msg = $this->getLogonMessage($player, $eventObj->sender);
				$r = new RoutableMessage($msg);
				$r->appendPath(new Source(Source::SYSTEM, "tracker"));
				$this->messageHub->handle($r);
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
		if ($this->db->table(self::DB_TABLE)->where("uid", $uid)->doesntExist()) {
			return;
		}
		$this->db->table(self::DB_TRACKING)
			->insert([
				"uid" => $uid,
				"dt" => time(),
				"event" => "logoff",
			]);

		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($eventObj): void {
				$msg = $this->getLogoffMessage($player, $eventObj->sender);
				$r = new RoutableMessage($msg);
				$r->appendPath(new Source(Source::SYSTEM, "tracker"));
				$this->messageHub->handle($r);
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
		/** @var Collection<TrackedUser> */
		$users = $this->db->table(self::DB_TABLE)
			->orderBy("name")
			->asObj(TrackedUser::class);
		$numrows = $users->count();
		if ($numrows === 0) {
			$msg = "No characters are on the track list.";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Tracked players<end>\n";
		foreach ($users as $user) {
			/** @var ?Tracking */
			$lastState = $this->db->table(self::DB_TRACKING)
				->where("uid", $user->uid)
				->orderByDesc("dt")
				->limit(1)
				->asObj(Tracking::class)
				->first();
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
		$deleted = $this->db->table(self::DB_TABLE)->where("uid", $uid)->delete();
		if (!$deleted) {
			$msg = "Character <highlight>$name<end> is not on the track list.";
			$sendto->reply($msg);
			return;
		}
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
		if (!$this->trackUid($uid, $name)) {
			$msg = "Character <highlight>$name<end> is already on the track list.";
			$sendto->reply($msg);
			return;
		}
		$msg = "Character <highlight>$name<end> has been added to the track list.";

		$sendto->reply($msg);
	}

	protected function trackUid(int $uid, string $name, ?string $sender=null): bool {
		if ($this->db->table(self::DB_TABLE)->where("uid", $uid)->exists()) {
			return false;
		}
		$this->db->table(self::DB_TABLE)
			->insert([
				"name" => $name,
				"uid" => $uid,
				"added_by" => $sender ?? $this->chatBot->vars["name"],
				"added_dt" => time(),
			]);
		$this->buddylistManager->add($name, 'tracking');
		return true;
	}

	/**
	 * @HandlesCommand("track")
	 * @Matches("/^track online$/i")
	 */
	public function trackOnlineCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var OnlinePlayer[] */
		$data = $this->db->table(self::DB_TABLE, "tu")
			->join("players AS p", "tu.name", "p.name")
			->where("p.dimension", $this->db->getDim())
			->orderBy("p.name")
			->select("p.*", "p.name AS pmain")
			->asObj(OnlinePlayer::class)
			->each(function (OnlinePlayer $player) {
				$player->afk = "";
				$player->online = true;
			})->filter(function(OnlinePlayer $player) {
				return $this->buddylistManager->isOnline($player->name) === true;
			})->toArray();
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
		/** @var Collection<Tracking> */
		$events = $this->db->table(self::DB_TRACKING)
			->where("uid", $uid)
			->orderByDesc("dt")
			->select("event", "dt")
			->asObj(Tracking::class);
		if ($events->isEmpty()) {
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
