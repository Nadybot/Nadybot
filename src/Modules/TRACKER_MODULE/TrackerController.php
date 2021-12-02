<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AdminManager,
	BuddylistManager,
	CmdContext,
	DB,
	DBSchema\Player,
	Event,
	EventManager,
	LoggerWrapper,
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
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PNonNumber;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	ONLINE_MODULE\OnlinePlayer,
	PRIVATE_CHANNEL_MODULE\PrivateChannelController,
	TOWER_MODULE\TowerAttackEvent,
};
use Nadybot\Modules\ORGLIST_MODULE\FindOrgController;
use Nadybot\Modules\ORGLIST_MODULE\Organization;
use Throwable;

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
	public GuildManager $guildManager;

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

	/** @Inject */
	public FindOrgController $findOrgController;

	/** @Logger */
	public LoggerWrapper $logger;

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

	/**
	 * @Event("timer(24hrs)")
	 * @Description("Download all tracked orgs' information")
	 */
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
				$this->chatBot->vars["dimension"],
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

	/**
	 * @Event("tower(attack)")
	 * @Description("Automatically track tower field attackers")
	 */
	public function trackTowerAttacks(TowerAttackEvent $eventObj): void {
		$attacker = $eventObj->attacker;
		$defGuild = $eventObj->defender->org ?? null;
		$defFaction = $eventObj->defender->faction ?? null;
		$trackWho = $this->settingManager->getInt('tracker_add_attackers') ?? 0;
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
		if (!$this->chatBot->isReady() || !is_string($eventObj->sender)) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		if ($this->db->table(self::DB_TABLE)->where("uid", $uid)->doesntExist()
			&& $this->db->table(self::DB_ORG_MEMBER)->where("uid", $uid)->doesntExist()
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
		$style = $this->settingManager->getInt('tracker_layout');
		$color = $online ? "<green>" : "<red>";
		switch ($style) {
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
		if (!$this->chatBot->isReady() || !is_string($eventObj->sender)) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		if ($this->db->table(self::DB_TABLE)->where("uid", $uid)->doesntExist()
			&& $this->db->table(self::DB_ORG_MEMBER)->where("uid", $uid)->doesntExist()
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
	 */
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

	/**
	 * @HandlesCommand("track")
	 */
	public function trackRemoveNameCommand(CmdContext $context, PRemove $action, PCharacter $char): void {
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

	/**
	 * @HandlesCommand("track")
	 */
	public function trackRemoveUidCommand(CmdContext $context, PRemove $action, int $uid): void {
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

	/**
	 * @HandlesCommand("track")
	 * @Mask $action add
	 */
	public function trackAddCommand(CmdContext $context, string $action, PCharacter $char): void {
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

	/**
	 * @HandlesCommand("track")
	 * @Mask $action addorg
	 */
	public function trackAddOrgIdCommand(CmdContext $context, string $action, int $orgId): void {
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
			(int)$this->chatBot->vars["dimension"],
			true,
			[$this, "updateRosterForOrg"],
			[$context, "reply"],
			"Added all members of <" . strtolower($org->faction) .">{$org->name}<end> to the roster."
		);
	}

	/**
	 * @HandlesCommand("track")
	 * @Mask $action addorg
	 */
	public function trackAddOrgNameCommand(CmdContext $context, string $action, PNonNumber $orgName): void {
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
		$blob = $this->formatOrglist($orgs);
		$msg = $this->text->makeBlob("Org Search Results for '{$orgName}' ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * @param Collection<Organization> $orgs
	 */
	public function formatOrglist(Collection $orgs): string {
		$orgs = $orgs->sortBy("name");
		$blob = "<header2>Matching orgs<end>\n";
		foreach ($orgs as $org) {
			$addLink = $this->text->makeChatcmd('track', "/tell <myname> track addorg {$org->id}");
			$blob .= "<tab>{$org->name} (<{$org->faction}>{$org->faction}<end>), ".
				"ID {$org->id} - <highlight>{$org->num_members}<end> members ".
				"[$addLink]\n";
		}
		return $blob;
	}

	/**
	 * @HandlesCommand("track")
	 * @Mask $action ((?:rem|del)org)
	 */
	public function trackRemOrgCommand(CmdContext $context, string $action, int $orgId): void {
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

	/**
	 * @HandlesCommand("track")
	 * @Mask $action (orgs?)
	 * @Mask $subAction list
	 */
	public function trackListOrgsCommand(CmdContext $context, string $action, ?string $subAction): void {
		$orgs = $this->db->table(static::DB_ORG, "to")
			->join("organizations AS o", "o.id", "to.org_id")
			->orderBy("name")
			->asObj(Organization::class);
		if ($orgs->isEmpty()) {
			$context->reply("There are currently no orgs being tracked.");
			return;
		}
		$lines = $orgs->map(function(Organization $o): string {
			$delLink = $this->text->makeChatcmd("remove", "/tell <myname> track remorg {$o->id}");
			return "<tab>{$o->name} (<" . strtolower($o->faction) . ">{$o->faction}<end>) - ".
				"<highlight>{$o->num_members}<end> members, added by <highlight>{$o->added_by}<end> ".
				"[{$delLink}]";
		});
		$blob = "<header2>Orgs being tracked<end>\n".
			$lines->join("\n");
		$msg = $this->text->makeBlob("Tracked orgs(" . $lines->count() . ")", $blob);
		$context->reply($msg);
	}

	public function updateRosterForOrg(?Guild $org, ?callable $callback, ...$args): void {
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
			$this->logger->error("Error adding org members for {$org->orgname}: " . $e->getMessage(), ["Exception" => $e]);
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
	 * @HandlesCommand("track")
	 * @Mask $action online
	 * @Mask $all all
	 * @Mask $edit --edit
	 */
	public function trackOnlineCommand(CmdContext $context, string $action, ?string $all, ?string $edit): void {
		$data2 = $this->db->table(self::DB_ORG_MEMBER, "tu")
			->join("players AS p", "tu.name", "p.name")
			->where("p.dimension", $this->db->getDim())
			->orderBy("p.name")
			->select("p.*", "p.name AS pmain", "tu.hidden");
		if (!isset($all)) {
			$data2->where("tu.hidden", false);
		}
		$sql = $this->db->table(self::DB_TABLE, "tu")
			->join("players AS p", "tu.name", "p.name")
			->where("p.dimension", $this->db->getDim())
			->orderBy("p.name")
			->select("p.*", "p.name AS pmain", "tu.hidden");
		if (!isset($all)) {
			$sql->where("tu.hidden", false);
		}
		/** @var OnlinePlayer[] */
		$data = $sql
			->union($data2)
			->asObj(OnlinePlayer::class)
			->each(function (OnlinePlayer $player) {
				$player->afk = "";
				$player->online = true;
			})->filter(function(OnlinePlayer $player) {
				return $this->buddylistManager->isOnline($player->name) === true;
			})->toArray();
		if (!count($data)) {
			$context->reply("No tracked players are currently online.");
			return;
		}
		$blob = $this->renderOnlineList($data, isset($edit));
		$footNotes = [];
		if (!isset($all)) {
			if (!isset($edit)) {
				$allLink = $this->text->makeChatcmd(
					"<symbol>track online all",
					"/tell <myname> track online all"
				);
			} else {
				$allLink = $this->text->makeChatcmd(
					"<symbol>track online all --edit",
					"/tell <myname> track online all --edit"
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
	 * @param OnlinePlayer[] $players
	 * @return string The blob
	 */
	public function renderOnlineList(array $players, bool $edit): string {
		$groupBy = $this->settingManager->getInt('tracker_group_by') ?? 1;
		$groups = [];
		if ($groupBy === static::GROUP_TL) {
			foreach ($players as $player) {
				$tl = $this->util->levelToTL($player->level??1);
				$groups[$tl] ??= (object)['title' => 'TL'.$tl, 'members' => [], 'sort' => $tl];
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
		} else {
			$groups["all"] ??= (object)['title' => "All tracked players", 'members' => $players, 'sort' => 0];
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
	 * @param OnlinePlayer[] $players The list of players in that group
	 * @return string The blob for this group
	 */
	public function renderPlayerGroup(array $players, int $groupBy, bool $edit): string {
		return "<tab>" . join(
			"\n<tab>",
			array_map(
				function(OnlinePlayer $player) use ($groupBy, $edit) {
					return $this->renderPlayerLine($player, $groupBy, $edit);
				},
				$players
			)
		);
	}

	/**
	 * Render a single online-line of a player
	 * @param OnlinePlayer $player The player to render
	 * @param int $groupBy Which grouping method to use. When grouping by prof, we don't show the prof icon
	 * @return string A single like without newlines
	 */
	public function renderPlayerLine(OnlinePlayer $player, int $groupBy, bool $edit): string {
		$faction = strtolower($player->faction);
		$blob = "";
		if ($groupBy !== static::GROUP_PROF) {
			if ($player->profession === null) {
				$blob .= "? ";
			} else {
				$blob .= "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".
					($this->onlineController->getProfessionId($player->profession??"_")??0) . "> ";
			}
		}
		if ($this->settingManager->getBool('tracker_use_faction_color')) {
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
			if ($player->hidden??false) {
				$blob .= " [{$unhideLink}]";
			} else {
				$blob .= " [{$hideLink}]";
			}
		}
		return $blob;
	}

	/**
	 * @HandlesCommand("track")
	 * @Mask $action hide
	 */
	public function trackHideUidCommand(CmdContext $context, string $action, int $uid): void {
		$this->chatBot->getName($uid, function(?string $name) use ($context, $uid): void {
			$this->trackHideCommand($context, $name ?? "UID {$uid}", $uid);
		});
	}

	/**
	 * @HandlesCommand("track")
	 * @Mask $action hide
	 */
	public function trackHideNameCommand(CmdContext $context, string $action, PCharacter $char): void {
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

	/**
	 * @HandlesCommand("track")
	 * @Mask $action unhide
	 */
	public function trackUnhideUidCommand(CmdContext $context, string $action, int $uid): void {
		$this->chatBot->getName($uid, function(?string $name) use ($context, $uid): void {
			$this->trackUnhideCommand($context, $name ?? "UID {$uid}", $uid);
		});
	}

	/**
	 * @HandlesCommand("track")
	 * @Mask $action unhide
	 */
	public function trackUnhideNameCommand(CmdContext $context, string $action, PCharacter $char): void {
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

	/**
	 * @HandlesCommand("track")
	 * @Mask $action (show|view)
	 */
	public function trackShowCommand(CmdContext $context, string $action, PCharacter $char): void {
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
