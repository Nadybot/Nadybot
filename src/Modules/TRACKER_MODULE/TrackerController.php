<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	ConfigFile,
	DB,
	DBSchema\Player,
	Event,
	EventManager,
	ModuleInstance,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	Modules\DISCORD\DiscordController,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PNonNumber,
	ParamClass\PProfession,
	ParamClass\PRemove,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	ORGLIST_MODULE\FindOrgController,
	ORGLIST_MODULE\Organization,
	TOWER_MODULE\TowerAttackEvent,
};
use Throwable;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "track",
		accessLevel: "member",
		description: "Show and manage tracked players",
	),
	NCA\ProvidesEvent("tracker(logon)"),
	NCA\ProvidesEvent("tracker(logoff)")
]
class TrackerController extends ModuleInstance implements MessageEmitter {
	public const DB_TABLE = "tracked_users_<myname>";
	public const DB_TRACKING = "tracking_<myname>";
	public const DB_ORG = "tracking_org_<myname>";
	public const DB_ORG_MEMBER = "tracking_org_member_<myname>";

	public const REASON_TRACKER = "tracking";
	public const REASON_ORG_TRACKER = "tracking_org";

	/** No grouping, just sorting */
	public const GROUP_NONE = 0;
	/** Group by title level */
	public const GROUP_TL = 1;
	/** Group by profession */
	public const GROUP_PROF = 2;
	/** Group by faction */
	public const GROUP_FACTION = 3;
	/** Group by org */
	public const GROUP_ORG = 4;
	/** Group by breed */
	public const GROUP_BREED = 5;
	/** Group by gender */
	public const GROUP_GENDER = 6;

	public const ATT_NONE = 0;
	public const ATT_OWN_ORG = 1;
	public const ATT_MEMBER_ORG = 2;
	public const ATT_CLAN = 4;
	public const ATT_OMNI = 8;
	public const ATT_NEUTRAL = 16;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public DiscordController $discordController;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public OnlineController $onlineController;

	#[NCA\Inject]
	public FindOrgController $findOrgController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** How to show if a tracked person logs on/off */
	#[NCA\Setting\Options(options: [
		'TRACK: "info" logged on/off.' => 0,
		'+/- "info"' => 1,
	])]
	public int $trackerLayout = 0;

	/** Use faction color for the name of the tracked person */
	#[NCA\Setting\Boolean]
	public bool $trackerUseFactionColor = false;

	/** Show the tracked person's level */
	#[NCA\Setting\Boolean]
	public bool $trackerShowLevel = false;

	/** Show the tracked person's profession */
	#[NCA\Setting\Boolean]
	public bool $trackerShowProf = false;

	/** Show the tracked person's org */
	#[NCA\Setting\Boolean]
	public bool $trackerShowOrg = false;

	/** Group online list by */
	#[NCA\Setting\Options(options: [
		'do not group' => self::GROUP_NONE,
		'title level' => self::GROUP_TL,
		'profession' => self::GROUP_PROF,
		'faction' => self::GROUP_FACTION,
		'org' => self::GROUP_ORG,
		'breed' => self::GROUP_BREED,
		'gender' => self::GROUP_GENDER,
	])]
	public int $trackerGroupBy = self::GROUP_NONE;

	/** Automatically track tower field attackers */
	#[NCA\Setting\Options(options: [
		"Off" => self::ATT_NONE,
		"Attacking my own org's tower fields" => self::ATT_OWN_ORG,
		"Attacking tower fields of bot members" => self::ATT_MEMBER_ORG,
		"Attacking Clan fields" => self::ATT_CLAN,
		"Attacking Omni fields" => self::ATT_OMNI,
		"Attacking Neutral fields" => self::ATT_NEUTRAL,
		"Attacking Non-Clan fields" => self::ATT_NEUTRAL|self::ATT_OMNI,
		"Attacking Non-Omni fields" => self::ATT_NEUTRAL|self::ATT_CLAN,
		"Attacking Non-Neutral fields" => self::ATT_CLAN|self::ATT_OMNI,
		"All" => self::ATT_NEUTRAL|self::ATT_CLAN|self::ATT_OMNI,
	])]
	public int $trackerAddAttackers = self::ATT_NONE;

	#[NCA\Setup]
	public function setup(): void {
		$this->messageHub->registerMessageEmitter($this);
	}

	#[NCA\Event(
		name: "connect",
		description: "Adds all players on the track list to the buddy list"
	)]
	public function trackedUsersConnectEvent(Event $eventObj): void {
		$this->db->table(self::DB_TABLE)
			->asObj(TrackedUser::class)
			->each(function(TrackedUser $row) {
				$this->buddylistManager->add($row->name, static::REASON_TRACKER);
			});
		$this->db->table(static::DB_ORG_MEMBER)
			->asObj(TrackingOrgMember::class)
			->each(function(TrackingOrgMember $row) {
				$this->buddylistManager->addId($row->uid, static::REASON_ORG_TRACKER);
			});
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(tracker)";
	}

	#[NCA\Event(
		name: "timer(24hrs)",
		description: "Download all tracked orgs' information"
	)]
	public function downloadOrgRostersEvent(Event $eventObj): void {
		$this->logger->notice("Starting Tracker Roster update");
		/** @var Collection<TrackingOrg> */
		$orgs = $this->db->table(static::DB_ORG)->asObj(TrackingOrg::class);

		$i = 0;
		foreach ($orgs as $org) {
			$i++;
			// Get the org info
			$this->guildManager->getByIdAsync(
				$org->org_id,
				$this->config->dimension,
				true,
				[$this, "updateRosterForOrg"],
				function() use (&$i) {
					if (--$i === 0) {
						$this->logger->notice("Finished Tracker Roster update");
					}
				}
			);
		}
	}

	#[NCA\Event(
		name: "tower(attack)",
		description: "Automatically track tower field attackers"
	)]
	public function trackTowerAttacks(TowerAttackEvent $eventObj): void {
		$attacker = $eventObj->attacker;
		if ($this->accessManager->checkAccess($attacker->name, "member")) {
			// Don't add members of the bot to the tracker
			return;
		}
		$defGuild = $eventObj->defender->org ?? null;
		$defFaction = $eventObj->defender->faction ?? null;
		$trackWho = $this->trackerAddAttackers;
		if ($trackWho === self::ATT_NONE) {
			return;
		}
		if ($trackWho === self::ATT_OWN_ORG ) {
			$attackingMyOrg = isset($defGuild) && $defGuild === $this->config->orgName;
			if (!$attackingMyOrg) {
				return;
			}
		}
		if ($trackWho === self::ATT_MEMBER_ORG) {
			if (!isset($defGuild)) {
				return;
			}
			$isOurGuild = $this->playerManager->searchByColumn(
				$this->config->dimension,
				"guild",
				$defGuild
			)->contains(function (Player $player): bool {
				return $this->accessManager->getAccessLevelForCharacter($player->name) !== "all";
			});

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
		if (isset($attacker->charid)) {
			$this->trackUid($attacker->charid, $attacker->name);
			return;
		}
		$this->chatBot->getUid($attacker->name, function (?int $uid) use ($attacker): void {
			if (isset($uid)) {
				$this->trackUid($uid, $attacker->name);
			}
		});
	}

	#[NCA\Event(
		name: "logOn",
		description: "Records a tracked user logging on"
	)]
	public function trackLogonEvent(UserStateEvent $eventObj): void {
		if (!$this->chatBot->isReady() || !is_string($eventObj->sender)) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		if ($uid === false) {
			return;
		}
		if (!$this->buddylistManager->buddyHasType($uid, static::REASON_TRACKER)
			&& !$this->buddylistManager->buddyHasType($uid, static::REASON_ORG_TRACKER)
		) {
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
		$color = $online ? "<green>" : "<red>";
		switch ($this->trackerLayout) {
			case 0:
				return "TRACK: %s logged {$color}" . ($online ? "on" : "off") . "<end>.";
			case 1:
			default:
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
		if ($this->trackerUseFactionColor) {
			$info = "<{$faction}>{$name}<end>";
		} else {
			$info = "<highlight>{$name}<end>";
		}
		$bracketed = [];
		$showLevel = $this->trackerShowLevel;
		$showProf = $this->trackerShowProf;
		$showOrg = $this->trackerShowOrg;
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

	#[NCA\Event(
		name: "logOff",
		description: "Records a tracked user logging off"
	)]
	public function trackLogoffEvent(UserStateEvent $eventObj): void {
		if (!$this->chatBot->isReady() || !is_string($eventObj->sender)) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		if ($uid === false) {
			return;
		}
		if (!$this->buddylistManager->buddyHasType($uid, static::REASON_TRACKER)
			&& !$this->buddylistManager->buddyHasType($uid, static::REASON_ORG_TRACKER)
		) {
			return;
		}
		$this->db->table(self::DB_TRACKING)
			->insert([
				"uid" => $uid,
				"dt" => time(),
				"event" => "logoff",
			]);

		// Prevent excessive "XXX logged off" messages after adding a whole org
		/** @var ?TrackingOrg */
		$orgMember = $this->db->table(self::DB_ORG_MEMBER, "om")
			->join(self::DB_ORG . " AS o", "om.org_id", "=", "o.org_id")
			->where("om.uid", $uid)
			->select("o.*")
			->asObj(TrackingOrg::class)
			->first();
		if (isset($orgMember) && (time() - $orgMember->added_dt->getTimestamp()) < 60) {
			return;
		}
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
		if ($player === null || !$this->trackerUseFactionColor) {
			$info = "<highlight>{$name}<end>";
		} else {
			$faction = strtolower($player->faction);
			$info = "<{$faction}>{$name}<end>";
		}
		return sprintf($format, $info);
	}

	/** See the list of users on the track list */
	#[NCA\HandlesCommand("track")]
	public function trackListCommand(CmdContext $context): void {
		/** @var Collection<TrackedUser> */
		$users = $this->db->table(self::DB_TABLE)
			->select("added_dt", "added_by", "name", "uid")
			->asObj(TrackedUser::class)
			->sortBy("name");
		$numrows = $users->count();
		if ($numrows === 0) {
			$msg = "No characters are on the track list.";
			$context->reply($msg);
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

			if (isset($lastState) && $lastState->event === 'logon') {
				$status = "<green>logon<end>";
			} elseif (isset($lastState) && $lastState->event == 'logoff') {
				$status = "<orange>logoff<end>";
			} else {
				$status = "<grey>None<end>";
			}

			$remove = $this->text->makeChatcmd('remove', "/tell <myname> track rem {$user->uid}");

			$history = $this->text->makeChatcmd('history', "/tell <myname> track show {$user->name}");

			$blob .= "<tab><highlight>{$user->name}<end> ({$status}{$lastAction}) - [{$remove}] [$history]\n";
		}

		$msg = $this->text->makeBlob("Tracklist ({$numrows})", $blob);
		$context->reply($msg);
	}

	/** Remove a player from the track list */
	#[NCA\HandlesCommand("track")]
	public function trackRemoveNameCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $char
	): void {
		$this->chatBot->getUid(
			$char(),
			function(?int $uid, string $name) use ($context): void {
				if (!isset($uid)) {
					$msg = "Character <highlight>{$name}<end> does not exist.";
					$context->reply($msg);
					return;
				}
				$this->trackRemoveCommand($context, $name, $uid);
			},
			$char()
		);
	}

	/** Remove a player from the track list */
	#[NCA\HandlesCommand("track")]
	public function trackRemoveUidCommand(
		CmdContext $context,
		PRemove $action,
		int $uid
	): void {
		$this->chatBot->getName($uid, function(?string $char) use ($uid, $context): void {
			$this->trackRemoveCommand($context, $char ?? "UID {$uid}", $uid);
		});
	}

	public function trackRemoveCommand(CmdContext $context, string $name, int $uid): void {
		$deleted = $this->db->table(self::DB_TABLE)->where("uid", $uid)->delete();
		if ($deleted) {
			$msg = "<highlight>{$name}<end> has been removed from the track list.";
			$this->buddylistManager->removeId($uid, static::REASON_TRACKER);

			$context->reply($msg);
			return;
		}
		/** @var ?TrackingOrgMember */
		$orgMember = $this->db->table(self::DB_ORG_MEMBER)
			->where("uid", $uid)
			->asObj(TrackingOrgMember::class)
			->first();
		if (!isset($orgMember)) {
			$msg = "<highlight>{$name}<end> is not on the track list.";
			$context->reply($msg);
			return;
		}
		$org = $this->findOrgController->getByID($orgMember->org_id);
		if (!isset($org)) {
			$msg = "In order to remove {$name} from the tracklist, ".
			"you need to remove the org ID {$orgMember->org_id} ";
		} else {
			$msg = "In order to remove {$name} from the tracklist, ".
				"you need to remove the org <".
				strtolower($org->faction) . ">{$org->name}<end> (ID {$org->id}) ";
		}

		$msg .= "from the tracker with <highlight><symbol>track remorg {$orgMember->org_id}<end>.";
		$context->reply($msg);
	}

	/** Add a player to the track list */
	#[NCA\HandlesCommand("track")]
	#[NCA\Help\Epilogue(
		"Tracked characters are announced via the source 'system(tracker)'\n".
		"Make sure you have routes in place to display these messages\n".
		"where you want to see them. See <a href='chatcmd:///tell <myname> help route'><symbol>help route</a> for more information."
	)]
	public function trackAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PCharacter $char
	): void {
		$this->chatBot->getUid($char(), function(?int $uid) use ($context, $char): void {
			if (!isset($uid)) {
				$msg = "Character <highlight>{$char}<end> does not exist.";
				$context->reply($msg);
				return;
			}
			if (!$this->trackUid($uid, $char())) {
				$msg = "Character <highlight>{$char}<end> is already on the track list.";
				$context->reply($msg);
				return;
			}
			$msg = "Character <highlight>{$char}<end> has been added to the track list.";

			$context->reply($msg);
		});
	}

	/** Add a whole organization to the track list */
	#[NCA\HandlesCommand("track")]
	public function trackAddOrgIdCommand(
		CmdContext $context,
		#[NCA\Str("addorg")] string $action,
		int $orgId
	): void {
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		$org = $this->findOrgController->getByID($orgId);
		if (!isset($org)) {
			$context->reply("There is no org #{$orgId}.");
			return;
		}

		if ($this->db->table(static::DB_ORG)->where("org_id", $orgId)->exists()) {
			$msg = "The org <" . strtolower($org->faction) . ">{$org->name}<end> is already being tracked.";
			$context->reply($msg);
			return;
		}
		$tOrg = new TrackingOrg();
		$tOrg->org_id = $orgId;
		$tOrg->added_by = $context->char->name;
		$this->db->insert(static::DB_ORG, $tOrg, null);
		$context->reply("Adding <" . strtolower($org->faction) . ">{$org->name}<end> to the tracker.");
		$this->guildManager->getByIdAsync(
			$orgId,
			$this->config->dimension,
			true,
			[$this, "updateRosterForOrg"],
			[$context, "reply"],
			"Added all members of <" . strtolower($org->faction) .">{$org->name}<end> to the roster."
		);
	}

	/** Add a whole organization to the track list */
	#[NCA\HandlesCommand("track")]
	public function trackAddOrgNameCommand(
		CmdContext $context,
		#[NCA\Str("addorg")] string $action,
		PNonNumber $orgName
	): void {
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		$orgs = new Collection($this->findOrgController->lookupOrg($orgName()));
		$count = $orgs->count();
		if ($count === 0) {
			$context->reply("No matches found.");
			return;
		}
		$blob = $this->formatOrglist(...$orgs->toArray());
		$msg = $this->text->makeBlob("Org Search Results for '{$orgName}' ($count)", $blob);
		$context->reply($msg);
	}

	public function formatOrglist(Organization ...$orgs): string {
		$orgs = (new Collection($orgs))->sortBy("name");
		$blob = "<header2>Matching orgs<end>\n";
		foreach ($orgs as $org) {
			$addLink = $this->text->makeChatcmd('track', "/tell <myname> track addorg {$org->id}");
			$blob .= "<tab>{$org->name} (<{$org->faction}>{$org->faction}<end>), ".
				"ID {$org->id} - <highlight>{$org->num_members}<end> members ".
				"[$addLink]\n";
		}
		return $blob;
	}

	/** Remove an organization from the track list */
	#[NCA\HandlesCommand("track")]
	public function trackRemOrgCommand(
		CmdContext $context,
		#[NCA\Regexp("(?:rem|del)org", example: "remorg")] string $action,
		int $orgId
	): void {
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		$org = $this->findOrgController->getByID($orgId);

		if ($this->db->table(static::DB_ORG)->where("org_id", $orgId)->doesntExist()) {
			$msg = "The org <highlight>#{$orgId}<end> is not being tracked.";
			if (isset($org)) {
				$msg = "The org <" . strtolower($org->faction) . ">{$org->name}<end> is not being tracked.";
			}
			$context->reply($msg);
			return;
		}
		$this->db->table(static::DB_ORG_MEMBER)
			->where("org_id", $orgId)
			->asObj(TrackingOrgMember::class)
			->each(function(TrackingOrgMember $exMember): void {
				$this->buddylistManager->removeId($exMember->uid, static::REASON_ORG_TRACKER);
			});
		$this->db->table(static::DB_ORG_MEMBER)
			->where("org_id", $orgId)
			->delete();
		$this->db->table(static::DB_ORG)
			->where("org_id", $orgId)
			->delete();
		$msg = "The org <highlight>#{$orgId}<end> is no longer being tracked.";
		if (isset($org)) {
			$msg = "The org <" . strtolower($org->faction) . ">{$org->name}<end> is no longer being tracked.";
		}
		$context->reply($msg);
	}

	/** List the organizations on the track list */
	#[NCA\HandlesCommand("track")]
	public function trackListOrgsCommand(
		CmdContext $context,
		#[NCA\Regexp("orgs?", example: "orgs")] string $action,
		#[NCA\Str("list")] ?string $subAction
	): void {
		$orgs = $this->db->table(static::DB_ORG)
			->asObj(TrackingOrg::class);
		$orgIds = $orgs->pluck("org_id")->filter()->toArray();
		$orgsByID = $this->findOrgController->getOrgsById(...$orgIds)
			->keyBy("id");
		$orgs = $orgs->each(function(TrackingOrg $o) use ($orgsByID): void {
			$o->org = $orgsByID->get($o->org_id);
		})->sort(function (TrackingOrg $o1, TrackingOrg $o2): int {
			return strcasecmp($o1->org->name??"", $o2->org->name??"");
		});

		$lines = $orgs->map(function(TrackingOrg $o): ?string {
			if (!isset($o->org)) {
				return null;
			}
			$delLink = $this->text->makeChatcmd("remove", "/tell <myname> track remorg {$o->org->id}");
			return "<tab>{$o->org->name} (<" . strtolower($o->org->faction) . ">{$o->org->faction}<end>) - ".
				"<highlight>{$o->org->num_members}<end> members, added by <highlight>{$o->added_by}<end> ".
				"[{$delLink}]";
		})->filter();
		if ($lines->isEmpty()) {
			$context->reply("There are currently no orgs being tracked.");
			return;
		}
		$blob = "<header2>Orgs being tracked<end>\n".
			$lines->join("\n");
		$msg = $this->text->makeBlob("Tracked orgs(" . $lines->count() . ")", $blob);
		$context->reply($msg);
	}

	/**
	 *
	 * @psalm-param null|callable(mixed ...) $callback
	 */
	public function updateRosterForOrg(?Guild $org, ?callable $callback, mixed ...$args): void {
		// Check if JSON file was downloaded properly
		if ($org === null) {
			$this->logger->error("Error downloading the guild roster JSON file");
			return;
		}

		if (count($org->members) === 0) {
			$this->logger->error("The organisation {$org->orgname} has no members. Not changing its roster");
			return;
		}

		// Save the current members in a hash for easy access
		/** @var Collection<TrackingOrgMember> */
		$oldMembers = $this->db->table(static::DB_ORG_MEMBER)
			->where("org_id", $org->guild_id)
			->asObj(TrackingOrgMember::class)
			->keyBy("uid");
		$this->db->beginTransaction();
		$toInsert = [];
		try {
			foreach ($org->members as $member) {
				/** @var ?TrackingOrgMember */
				$oldMember = $oldMembers->get($member->charid);
				if (isset($oldMember) && $oldMember->name === $member->name) {
					$oldMembers->forget((string)$oldMember->uid);
					continue;
				}
				if (isset($oldMember)) {
					$this->db->table(static::DB_ORG_MEMBER)
						->where("uid", $oldMember->uid)
						->update(["name" => $member->name]);
					$oldMembers->forget((string)$oldMember->uid);
				} else {
					$toInsert []= [
						"org_id" => $org->guild_id,
						"uid" => $member->charid,
						"name" => $member->name,
					];
				}
			}
			if (count($toInsert)) {
				$maxBuddies = $this->chatBot->getBuddyListSize();
				$numBuddies = $this->buddylistManager->getUsedBuddySlots();
				if (count($toInsert) + $numBuddies > $maxBuddies) {
					if (isset($callback)) {
						$callback(
							"You cannot add " . count($toInsert) . " more ".
							"characters to the tracking list, you only have ".
							($maxBuddies - $numBuddies) . " slots left. Please ".
							"install aochatproxy, or add more characters to your ".
							"existing configuration."
						);
					}
					$this->db->rollback();
					$this->db->table(static::DB_ORG)->where("org_id", $org->guild_id)->delete();
					return;
				}
				$this->db->table(static::DB_ORG_MEMBER)
					->chunkInsert($toInsert);
				foreach ($toInsert as $buddy) {
					$this->buddylistManager->addId($buddy["uid"], static::REASON_ORG_TRACKER);
				}
			}
			$oldMembers->each(function(TrackingOrgMember $exMember): void {
				$this->buddylistManager->removeId($exMember->uid, static::REASON_ORG_TRACKER);
			});
		} catch (Throwable $e) {
			$this->db->rollback();
			$this->logger->error("Error adding org members for {$org->orgname}: " . $e->getMessage(), ["exception" => $e]);
			return;
		}
		$this->db->commit();
		if (isset($callback)) {
			$callback(...$args);
		}
	}

	protected function trackUid(int $uid, string $name, ?string $sender=null): bool {
		if ($this->db->table(self::DB_TABLE)->where("uid", $uid)->exists()) {
			return false;
		}
		$this->db->table(self::DB_TABLE)
			->insert([
				"name" => $name,
				"uid" => $uid,
				"added_by" => $sender ?? $this->chatBot->char->name,
				"added_dt" => time(),
			]);
		$this->buddylistManager->addId($uid, static::REASON_TRACKER);
		return true;
	}

	/**
	 * Show a nice online list of everyone on your track list
	 *
	 * By default, this will not show chars hidden via '<symbol>track hide', unless you give 'all'
	 * To get links for removing and hiding/unhiding characters, add '--edit'
	 */
	#[NCA\HandlesCommand("track")]
	#[NCA\Help\Example("<symbol>track online")]
	#[NCA\Help\Example("<symbol>track online doc")]
	#[NCA\Help\Example("<symbol>track all --edit")]
	public function trackOnlineCommand(
		CmdContext $context,
		#[NCA\Str("online")] string $action,
		?PProfession $profession,
		#[NCA\Str("all")] ?string $all,
		#[NCA\Str("--edit")] ?string $edit
	): void {
		$hiddenChars = $this->db->table(self::DB_ORG_MEMBER)
			->select("name")
			->where("hidden", true)
			->union(
				$this->db->table(self::DB_TABLE)
					->select("name")
					->where("hidden", true)
			)->pluckAs("name", "string")
			->unique()
			->mapToDictionary(fn (string $s): array => [$s => true])
			->toArray();
		$data1 = $this->db->table(self::DB_ORG_MEMBER)->select("name");
		$data2 = $this->db->table(self::DB_TABLE)->select("name");
		if (!isset($all)) {
			$data1->where("hidden", false);
			$data2->where("hidden", false);
		}
		$trackedUsers = $data1
			->union($data2)
			->pluckAs("name", "string")
			->unique()
			->filter(function (string $name): bool {
				return $this->buddylistManager->isOnline($name) ?? false;
			})
			->toArray();
		$data = $this->playerManager->searchByNames($this->config->dimension, ...$trackedUsers)
			->sortBy("name")
			->map(function (Player $p) use ($hiddenChars): OnlineTrackedUser {
				$op = OnlineTrackedUser::fromPlayer($p);
				$op->pmain ??= $op->name;
				$op->online = true;
				$op->hidden = isset($hiddenChars[$op->name]);
				return $op;
			});
		if (isset($profession)) {
			$data = $data->where("profession", $profession());
		}
		if ($data->isEmpty()) {
			$context->reply("No tracked players are currently online.");
			return;
		}
		$data = $data->toArray();
		$blob = $this->renderOnlineList($data, isset($edit));
		$footNotes = [];
		if (!isset($all)) {
			$prof = isset($profession) ? $profession() . " " : "";
			if (!isset($edit)) {
				$allLink = $this->text->makeChatcmd(
					"<symbol>track online {$prof}all",
					"/tell <myname> track online {$prof}all"
				);
			} else {
				$allLink = $this->text->makeChatcmd(
					"<symbol>track online {$prof}all --edit",
					"/tell <myname> track online {$prof}all --edit"
				);
			}
			$footNotes []= "<i>Use {$allLink} to see hidden characters.</i>";
		}
		if (!isset($edit)) {
			$editLink = $this->text->makeChatcmd(
				"<symbol>{$context->message} --edit",
				"/tell <myname> {$context->message} --edit"
			);
			$footNotes []= "<i>Use {$editLink} to see more options.</i>";
		}
		if (!empty($footNotes)) {
			$blob .= "\n\n" . join("\n", $footNotes);
		}
		$msg = $this->text->makeBlob("Online tracked players (" . count($data). ")", $blob);
		$context->reply($msg);
	}

	/**
	 * Get the blob with details about the tracked players currently online
	 * @param OnlineTrackedUser[] $players
	 * @return string The blob
	 */
	public function renderOnlineList(array $players, bool $edit): string {
		$groupBy = $this->trackerGroupBy;
		$groups = [];
		if ($groupBy === static::GROUP_TL) {
			foreach ($players as $player) {
				$tl = $this->util->levelToTL($player->level??1);
				$groups[$tl] ??= (object)[
					'title' => 'TL'.$tl,
					'members' => [],
					'sort' => $tl
				];
				$groups[$tl]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_PROF) {
			foreach ($players as $player) {
				$prof = $player->profession??"Unknown";
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".($this->onlineController->getProfessionId($player->profession??"_")??0).">";
				$groups[$prof] ??= (object)[
					'title' => $profIcon . " " . ($player->profession ?? "Unknown"),
					'members' => [],
					'sort' => $player->profession??"Unknown",
				];
				$groups[$prof]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_FACTION) {
			foreach ($players as $player) {
				$faction = $player->faction;
				$groups[$faction] ??= (object)[
					'title' => $faction,
					'members' => [],
					'sort' => $faction,
				];
				$groups[$faction]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_ORG) {
			foreach ($players as $player) {
				$org = $player->guild;
				if ($org === null || $org === '') {
					$org = '&lt;None&gt;';
				}
				$groups[$org] ??= (object)[
					'title' => $org,
					'members' => [],
					'sort' => $org,
				];
				$groups[$org]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_BREED) {
			foreach ($players as $player) {
				$breed = $player->breed;
				$groups[$breed] ??= (object)[
					'title' => $breed,
					'members' => [],
					'sort' => $breed,
				];
				$groups[$breed]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_GENDER) {
			foreach ($players as $player) {
				$gender = $player->gender;
				$groups[$gender] ??= (object)[
					'title' => $gender,
					'members' => [],
					'sort' => $gender,
				];
				$groups[$gender]->members []= $player;
			}
		} else {
			$groups["all"] = (object)[
				'title' => "All tracked players",
				'members' => $players,
				'sort' => 0
			];
		}
		usort($groups, function(object $a, object $b): int {
			return $a->sort <=> $b->sort;
		});
		$parts = [];
		foreach ($groups as $group) {
			$parts []= "<header2>{$group->title} (" . count($group->members) . ")<end>\n".
				$this->renderPlayerGroup($group->members, $groupBy, $edit);
		}

		return join("\n\n", $parts);
	}

	/**
	 * Return the content of the online list for one player group
	 * @param OnlineTrackedUser[] $players The list of players in that group
	 * @return string The blob for this group
	 */
	public function renderPlayerGroup(array $players, int $groupBy, bool $edit): string {
		usort($players, function(OnlineTrackedUser $p1, OnlineTrackedUser $p2): int {
			return strnatcmp($p1->name, $p2->name);
		});
		return "<tab>" . join(
			"\n<tab>",
			array_map(
				function(OnlineTrackedUser $player) use ($groupBy, $edit) {
					return $this->renderPlayerLine($player, $groupBy, $edit);
				},
				$players
			)
		);
	}

	/**
	 * Render a single online-line of a player
	 * @param OnlineTrackedUser $player The player to render
	 * @param int $groupBy Which grouping method to use. When grouping by prof, we don't show the prof icon
	 * @return string A single like without newlines
	 */
	public function renderPlayerLine(OnlineTrackedUser $player, int $groupBy, bool $edit): string {
		$faction = strtolower($player->faction);
		$blob = "";
		if ($groupBy !== static::GROUP_PROF) {
			if ($player->profession === null) {
				$blob .= "? ";
			} else {
				$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".
					($this->onlineController->getProfessionId($player->profession)??0) . "> ";
			}
		}
		if ($this->trackerUseFactionColor) {
			$blob .= "<{$faction}>{$player->name}<end>";
		} else {
			$blob .= "<highlight>{$player->name}<end>";
		}
		$prof = $this->util->getProfessionAbbreviation($player->profession??"Unknown");
		$blob .= " ({$player->level}/<green>{$player->ai_level}<end>, {$prof})";
		if ($player->guild !== null && $player->guild !== '') {
			$blob .= " :: <{$faction}>{$player->guild}<end> ({$player->guild_rank})";
		}
		if ($edit) {
			$historyLink = $this->text->makeChatcmd("history", "/tell <myname> track show {$player->name}");
			$removeLink = $this->text->makeChatcmd("untrack", "/tell <myname> track rem {$player->charid}");
			$hideLink = $this->text->makeChatcmd("hide", "/tell <myname> track hide {$player->charid}");
			$unhideLink = $this->text->makeChatcmd("unhide", "/tell <myname> track unhide {$player->charid}");
			$blob .= " [{$removeLink}] [{$historyLink}]";
			if ($player->hidden) {
				$blob .= " [{$unhideLink}]";
			} else {
				$blob .= " [{$hideLink}]";
			}
		}
		return $blob;
	}

	/** Hide a character from the '<symbol>track online' list */
	#[NCA\HandlesCommand("track")]
	public function trackHideUidCommand(
		CmdContext $context,
		#[NCA\Str("hide")] string $action,
		int $uid
	): void {
		$this->chatBot->getName($uid, function(?string $name) use ($context, $uid): void {
			$this->trackHideCommand($context, $name ?? "UID {$uid}", $uid);
		});
	}

	/** Hide a character from the '<symbol>track online' list */
	#[NCA\HandlesCommand("track")]
	public function trackHideNameCommand(
		CmdContext $context,
		#[NCA\Str("hide")] string $action,
		PCharacter $char
	): void {
		$this->chatBot->getUid($char(), function(?int $uid) use ($context, $char): void {
			if (!isset($uid)) {
				$msg = "Character <highlight>{$char}<end> does not exist.";
				$context->reply($msg);
				return;
			}
			$this->trackHideCommand($context, $char(), $uid);
		});
	}

	public function trackHideCommand(CmdContext $context, string $name, int $uid): void {
		$updated = $this->db->table(self::DB_TABLE)
			->where("uid", $uid)
			->update(["hidden" => true])
			?: $this->db->table(self::DB_ORG_MEMBER)
			->where("uid", $uid)
			->update(["hidden" => true]);
		if ($updated === 0) {
			$msg = "<highlight>{$name}<end> is not tracked.";
			$context->reply($msg);
			return;
		}
		$msg = "<highlight>{$name}<end> is no longer shown in <highlight><symbol>track online<end>.";
		$context->reply($msg);
	}

	/** Show a hidden a character on the '<symbol>track online' list again */
	#[NCA\HandlesCommand("track")]
	public function trackUnhideUidCommand(
		CmdContext $context,
		#[NCA\Str("unhide")] string $action,
		int $uid
	): void {
		$this->chatBot->getName($uid, function(?string $name) use ($context, $uid): void {
			$this->trackUnhideCommand($context, $name ?? "UID {$uid}", $uid);
		});
	}

	/** Show a hidden a character on the '<symbol>track online' list again */
	#[NCA\HandlesCommand("track")]
	public function trackUnhideNameCommand(
		CmdContext $context,
		#[NCA\Str("unhide")] string $action,
		PCharacter $char
	): void {
		$this->chatBot->getUid($char(), function(?int $uid) use ($context, $char): void {
			if (!isset($uid)) {
				$msg = "Character <highlight>{$char}<end> does not exist.";
				$context->reply($msg);
				return;
			}
			$this->trackUnhideCommand($context, $char(), $uid);
		});
	}

	public function trackUnhideCommand(CmdContext $context, string $name, int $uid): void {
		$updated = $this->db->table(self::DB_TABLE)
			->where("uid", $uid)
			->update(["hidden" => false])
			?:
			$this->db->table(self::DB_ORG_MEMBER)
				->where("uid", $uid)
				->update(["hidden" => false]);
		if ($updated === 0) {
			$msg = "<highlight>{$name}<end> is not tracked.";
			$context->reply($msg);
			return;
		}
		$msg = "<highlight>{$name}<end> is now shown in <highlight><symbol>track online<end> again.";
		$context->reply($msg);
	}

	/** See the track history of a given character */
	#[NCA\HandlesCommand("track")]
	public function trackShowCommand(
		CmdContext $context,
		#[NCA\Str("show", "view")] string $action,
		PCharacter $char
	): void {
		$this->chatBot->getUid($char(), function(?int $uid) use ($context, $char): void {
			if (!isset($uid)) {
				$msg = "<highlight>{$char}<end> does not exist.";
				$context->reply($msg);
				return;
			}
			/** @var Collection<Tracking> */
			$events = $this->db->table(self::DB_TRACKING)
				->where("uid", $uid)
				->orderByDesc("dt")
				->select("event", "dt")
				->asObj(Tracking::class);
			if ($events->isEmpty()) {
				$msg = "<highlight>{$char}<end> has never logged on or is not being tracked.";
				$context->reply($msg);
				return;
			}
			$blob = "<header2>All events for {$char}<end>\n";
			foreach ($events as $event) {
				if ($event->event == 'logon') {
					$status = "<green>logon<end>";
				} elseif ($event->event == 'logoff') {
					$status = "<orange>logoff<end>";
				} else {
					$status = "<grey>unknown<end>";
				}
				$blob .= "<tab> {$status} - " . $this->util->date($event->dt) ."\n";
			}

			$msg = $this->text->makeBlob("Track History for {$char}", $blob);
			$context->reply($msg);
		});
	}
}
