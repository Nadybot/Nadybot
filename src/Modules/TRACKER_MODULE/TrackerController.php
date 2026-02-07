<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Exception;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	Collection,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	EventManager,
	Events\ConnectEvent,
	Events\LogoffEvent,
	Events\LogonEvent,
	Events\TimerEvent,
	MessageHub,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Text,
	Types\AccessLevel,
	Types\Faction,
	Types\MessageEmitter,
	Types\Profession,
	Types\TitleLevel,
	Util,
};
use Nadybot\Core\Attributes\Parameter\{NonNumberStr, Regexp, Remove, Str};
use Nadybot\Modules\{
	ORGLIST_MODULE\FindOrgController,
	ORGLIST_MODULE\Organization,
	PVP_MODULE\Event\TowerAttackEvent,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HasTests,
	NCA\DefineCommand(
		command: 'track',
		accessLevel: AccessLevel::Member,
		description: 'Show and manage tracked players',
	),
]
class TrackerController extends ModuleInstance implements MessageEmitter {
	/** @var 'tracking' */
	public const REASON_TRACKER = 'tracking';

	/** @var 'tracking_org' */
	public const REASON_ORG_TRACKER = 'tracking_org';

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

	/** Tracker logon-message */
	#[NCA\DefineSetting(
		type: 'tracker_format',
		options: [
			'TRACK: <highlight>{name}<end> logged <on>on<end>.',
			'TRACK: <{faction}>{name}<end> ({level}, {profession}), <{faction}>{org}<end> logged <on>on<end>.',
			'<{faction}>{FACTION}<end>: <{faction}>{name}<end>, TL<highlight>{tl}<end> {prof} logged <on>on<end>.',
			'<on>+<end> <{faction}>{name}<end>',
			'<on>+<end> <{faction}>{name}<end> ({level}, {prof}), <{faction}>{org}<end>',
		]
	)]
	public string $trackerLogon = 'TRACK: <{faction}>{name}<end> ({level}, {profession}), <{faction}>{org}<end> logged <on>on<end>.';

	/** Tracker logoff-message */
	#[NCA\DefineSetting(
		type: 'tracker_format',
		options: [
			'TRACK: <{faction}>{name}<end> logged <off>off<end>.',
			'<{faction}>{FACTION}<end>: <{faction}>{name}<end>, TL<highlight>{tl}<end> {prof} logged <off>off<end>',
			'<off>-<end> <{faction}>{name}<end>',
		]
	)]
	public string $trackerLogoff = 'TRACK: <{faction}>{name}<end> logged <off>off<end>.';

	/** Use faction color for the name in the online list */
	#[NCA\Setting\Boolean]
	public bool $trackerUseFactionColor = true;

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
		'Off' => self::ATT_NONE,
		"Attacking my own org's tower fields" => self::ATT_OWN_ORG,
		'Attacking tower fields of bot members' => self::ATT_MEMBER_ORG,
		'Attacking Clan fields' => self::ATT_CLAN,
		'Attacking Omni fields' => self::ATT_OMNI,
		'Attacking Neutral fields' => self::ATT_NEUTRAL,
		'Attacking Non-Clan fields' => self::ATT_NEUTRAL|self::ATT_OMNI,
		'Attacking Non-Omni fields' => self::ATT_NEUTRAL|self::ATT_CLAN,
		'Attacking Non-Neutral fields' => self::ATT_CLAN|self::ATT_OMNI,
		'All' => self::ATT_NEUTRAL|self::ATT_CLAN|self::ATT_OMNI,
	])]
	public int $trackerAddAttackers = self::ATT_NONE;

	/** Time after which characters not logging on will be un-tracked */
	#[NCA\Setting\TimeOrOff(
		options: [
			'off',
			'1week',
			'2weeks',
			'3weeks',
			'1month',
			'2months',
			'3months',
			'6months',
			'1year',
		]
	)]
	public int $trackerAutoUntrack = 0;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private GuildManager $guildManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private FindOrgController $findOrgController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Setup]
	public function setup(): void {
		$this->messageHub->registerMessageEmitter($this);
	}

	/** Adds all players on the track list to the buddy list */
	#[NCA\HandlesEvent]
	public function trackedUsersConnectEvent(ConnectEvent $eventObj): void {
		$this->db->table(TrackedUser::getTable())
			->asObj(TrackedUser::class)
			->each(function (TrackedUser $row): void {
				$this->buddylistManager->addName($row->name, static::REASON_TRACKER);
			});
		$this->db->table(TrackingOrgMember::getTable())
			->asObj(TrackingOrgMember::class)
			->each(function (TrackingOrgMember $row): void {
				$this->buddylistManager->addId($row->uid, static::REASON_ORG_TRACKER);
			});
	}

	/** Stop tracking inactive characters */
	#[NCA\Timer(interval: '24hrs')]
	public function untrackInactiveCharacters(): void {
		if ($this->trackerAutoUntrack === 0) {
			return;
		}

		$users = $this->db->table(TrackedUser::getTable())
			->asObj(TrackedUser::class)
			->keyByInt('uid');

		$query = $this->db->table(TrackedUser::getTable(), 't');
		$query->join(Tracking::getTable() . ' as ev', 'ev.uid', '=', 't.uid')
			->where('ev.event', 'logon')
			->groupBy('ev.uid')
			->select(['ev.uid', $query->raw($query->colFunc('max', 'ev.dt', 'dt'))])
			->asObj(LastLogin::class)
			->each(function (LastLogin $row) use (&$users): void {
				$age = time() - $row->dt;
				$this->untrackIfTooOld($row->uid, $age);
				$users->forget($row->uid);
			});
		$users->each(function (TrackedUser $user): void {
			$age = time() - $user->added_dt;
			$this->untrackIfTooOld($user->uid, $age);
		});
	}

	public function getChannelName(): string {
		return Source::SYSTEM . '(tracker)';
	}

	/** Download all tracked orgs' information */
	#[NCA\Timer(interval: '24hrs')]
	public function downloadOrgRostersEvent(TimerEvent $eventObj): void {
		$orgs = $this->db->table(TrackingOrg::getTable())->asObj(TrackingOrg::class);
		try {
			foreach ($orgs as $org) {
				$orgData = $this->guildManager->byId($org->org_id, $this->config->main->dimension, true);
				$this->updateRosterForOrg($orgData);
			}
		} catch (Throwable $e) {
			$this->logger->error('{error}', [
				'error' => $e->getMessage(),
				'exception' => $e->getPrevious(),
			]);
		}
		$this->logger->notice('Finished Tracker Roster update');
	}

	/** Automatically track tower field attackers */
	#[NCA\HandlesEvent]
	public function trackTowerAttacks(TowerAttackEvent $eventObj): void {
		$attacker = $eventObj->attack->attacker;
		if ($this->accessManager->checkAccess($attacker->name, AccessLevel::Member)) {
			// Don't add members of the bot to the tracker
			return;
		}
		$defGuild = $eventObj->attack->defender->name;
		$defFaction = $eventObj->attack->defender->faction;
		$trackWho = $this->trackerAddAttackers;
		if ($trackWho === self::ATT_NONE) {
			return;
		}
		if ($trackWho === self::ATT_OWN_ORG) {
			$attackingMyOrg = $defGuild === $this->config->general->orgName;
			if (!$attackingMyOrg) {
				return;
			}
		}
		if ($trackWho === self::ATT_MEMBER_ORG) {
			$isOurGuild = $this->playerManager->searchByColumn(
				$this->config->main->dimension,
				'guild',
				$defGuild
			)->contains(function (Player $player): bool {
				return $this->accessManager->getAccessLevelForCharacter($player->name) !== AccessLevel::All;
			});

			if (!$isOurGuild) {
				return;
			}
		}
		if ($trackWho >= self::ATT_CLAN) {
			if ($defFaction === Faction::Clan && ($trackWho & self::ATT_CLAN) === 0) {
				return;
			}
			if ($defFaction === Faction::Omni && ($trackWho & self::ATT_OMNI) === 0) {
				return;
			}
			if ($defFaction === Faction::Neutral && ($trackWho & self::ATT_NEUTRAL) === 0) {
				return;
			}
		}
		if (isset($attacker->character_id)) {
			$this->trackUid($attacker->character_id, $attacker->name);
			return;
		}
		$uid = $this->chatBot->getUid($attacker->name);
		if (isset($uid)) {
			$this->trackUid($uid, $attacker->name);
		}
	}

	/** Records a tracked user logging on */
	#[NCA\HandlesEvent]
	public function trackLogonEvent(LogonEvent $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$uid = $this->chatBot->getUid($eventObj->sender);
		if ($uid === null) {
			return;
		}
		if (!$this->buddylistManager->buddyHasType($uid, static::REASON_TRACKER)
			&& !$this->buddylistManager->buddyHasType($uid, static::REASON_ORG_TRACKER)
		) {
			return;
		}
		$this->db->insert(new Tracking(
			uid: $uid,
			dt: time(),
			event: 'logon',
		));

		$event = new TrackerLogonEvent(player: $eventObj->sender, uid: $uid);
		$this->eventManager->dispatch($event);

		$player = $this->playerManager->byName($eventObj->sender);

		$msg = $this->getLogonMessage($player, $eventObj->sender);
		$r = new RoutableMessage($msg);
		$r->appendPath(new Source(Source::SYSTEM, 'tracker'));
		$this->messageHub->handle($r);
	}

	/** Get the message to show when a tracked player logs on */
	public function getLogonMessage(?Player $player, string $name): string {
		return $this->getLogMessage($player, $name, $this->trackerLogon);
	}

	/** Get the message to show when a tracked player logs off */
	public function getLogoffMessage(?Player $player, string $name): string {
		return $this->getLogMessage($player, $name, $this->trackerLogoff);
	}

	/** Get the message to show when a tracked player logs on */
	public function getLogMessage(?Player $player, string $name, string $format): string {
		$replacements = [
			'faction' => 'neutral',
			'name' => $name,
			'profession' => 'Unknown',
			'dimension' => $this->config->main->dimension,
			'prof' => '???',
			'level' => '?',
			'ai_level' => '?',
			'org' => '&lt;no org&gt;',
			'org_rank' => '&lt;no rank&gt;',
			'breed' => '?',
			'gender' => '?',
			'tl' => '?',
		];
		if (isset($player)) {
			$replacements['faction'] = $player->faction->lower();
			if (isset($player->profession)) {
				$replacements['profession'] = $player->profession->value;
				$replacements['prof'] = $player->profession->short();
			}
			$replacements['dimension'] = $player->dimension;
			$replacements['org'] = $player->guild ?? '&lt;no org&gt;';
			$replacements['gender'] = strtolower($player->gender);
			$replacements['org_rank'] = $player->guild_rank ?? '&lt;no rank&gt;';
			$replacements['breed'] = $player->breed;
			if (isset($player->level)) {
				$replacements['level'] = "<highlight>{$player->level}<end>/<green>{$player->ai_level}<end>";
				$replacements['tl'] = TitleLevel::fromLevel($player->level ?? 1)->value;
			}
		}
		$replacements['Gender'] = ucfirst($replacements['gender']);
		$replacements['Faction'] = ucfirst($replacements['faction']);
		$replacements['FACTION'] = strtoupper($replacements['faction']);
		if (!isset($player) || !isset($player->guild) || !strlen($player->guild)) {
			$format = Safe::pregReplace("/(?: of|,)?\s+<[^>]+>\{org\}<end>/", '', $format);
			$format = Safe::pregReplace("/(?: of|,)?\s+\{org\}/", '', $format);
			$format = Safe::pregReplace("/\s+\{org_rank\}/", '', $format);
		}
		$subst = [];
		foreach ($replacements as $key => $value) {
			$subst['{' . $key . '}'] = (string)$value;
		}

		return str_replace(
			array_keys($subst),
			array_values($subst),
			$format
		);
	}

	/** Records a tracked user logging off */
	#[NCA\HandlesEvent]
	public function trackLogoffEvent(LogoffEvent $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$uid = $this->chatBot->getUid($eventObj->sender);
		if ($uid === null) {
			return;
		}
		if (!$this->buddylistManager->buddyHasType($uid, static::REASON_TRACKER)
			&& !$this->buddylistManager->buddyHasType($uid, static::REASON_ORG_TRACKER)
		) {
			return;
		}

		// Prevent excessive "XXX logged off" messages after adding a whole org
		$orgMember = $this->db->table(TrackingOrgMember::getTable(), 'om')
			->join(TrackingOrg::getTable() . ' AS o', 'om.org_id', '=', 'o.org_id')
			->where('om.uid', $uid)
			->select('o.*')
			->firstObj(TrackingOrg::class);
		if (isset($orgMember) && (time() - $orgMember->added_dt->getTimestamp()) < 60) {
			return;
		}
		$this->db->insert(new Tracking(
			uid: $uid,
			dt: time(),
			event: 'logoff',
		));

		$event = new TrackerLogoffEvent(player: $eventObj->sender, uid: $uid);
		$this->eventManager->dispatch($event);

		$player = $this->playerManager->byName($eventObj->sender);
		$msg = $this->getLogoffMessage($player, $eventObj->sender);
		$r = new RoutableMessage($msg);
		$r->appendPath(new Source(Source::SYSTEM, 'tracker'));
		$this->messageHub->handle($r);
	}

	/** See the list of users on the track list */
	#[NCA\HandlesCommand('track')]
	public function trackListCommand(CmdContext $context): void {
		$users = $this->db->table(TrackedUser::getTable())
			->select(['added_dt', 'added_by', 'name', 'uid'])
			->asObj(TrackedUser::class)
			->sortBy('name');
		$numrows = $users->count();
		if ($numrows === 0) {
			$msg = 'No characters are on the track list.';
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Tracked players<end>\n";
		foreach ($users as $user) {
			$lastState = $this->db->table(Tracking::getTable())
				->where('uid', $user->uid)
				->orderByDesc('id')
				->firstObj(Tracking::class);
			$lastAction = '';
			if ($lastState !== null) {
				$lastAction = ' ' . Util::date($lastState->dt);
			}

			if (isset($lastState) && $lastState->event === 'logon') {
				$status = '<on>logon<end>';
			} elseif (isset($lastState) && $lastState->event === 'logoff') {
				$status = '<off>logoff<end>';
			} else {
				$status = '<grey>None<end>';
			}

			$remove = Text::makeChatcmd('remove', "/tell <myname> track rem {$user->uid}");

			$history = Text::makeChatcmd('history', "/tell <myname> track show {$user->name}");

			$blob .= "<tab><highlight>{$user->name}<end> ({$status}{$lastAction}) - [{$remove}] [{$history}]\n";
		}

		$msg = Text::makeBlob("Tracklist ({$numrows})", $blob);
		$context->reply($msg);
	}

	/** Remove a player from the track list */
	#[NCA\HandlesCommand('track')]
	public function trackRemoveNameCommand(
		CmdContext $context,
		#[Remove] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if (!isset($uid)) {
			$msg = "Character <highlight>{$char}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$this->trackRemoveCommand($context, $char(), $uid);
	}

	/** Remove a player from the track list */
	#[NCA\HandlesCommand('track')]
	public function trackRemoveUidCommand(
		CmdContext $context,
		#[Remove] string $action,
		int $uid
	): void {
		$char = $this->chatBot->getName($uid);
		$this->trackRemoveCommand($context, $char ?? "UID {$uid}", $uid);
	}

	public function trackRemoveCommand(CmdContext $context, string $name, int $uid): void {
		$deleted = $this->db->table(TrackedUser::getTable())->where('uid', $uid)->delete();
		if ($deleted) {
			$msg = "<highlight>{$name}<end> has been removed from the track list.";
			$this->buddylistManager->removeId($uid, static::REASON_TRACKER);
			$this->db->table(Tracking::getTable())->where('uid', $uid)->delete();

			$context->reply($msg);
			return;
		}

		$orgMember = $this->db->table(TrackingOrgMember::getTable())
			->where('uid', $uid)
			->firstObj(TrackingOrgMember::class);
		if (!isset($orgMember)) {
			$msg = "<highlight>{$name}<end> is not on the track list.";
			$context->reply($msg);
			return;
		}

		$msg = "Removed <highlight>{$name}<end> from the tracklist, but ".
			'they were tracked, because the whole org';
		$deleted = $this->db->table(TrackingOrgMember::getTable())
			->where('uid', $uid)
			->delete();
		if ($deleted) {
			$this->buddylistManager->removeId($uid, static::REASON_ORG_TRACKER);
		}
		$org = $this->findOrgController->getByID($orgMember->org_id);
		if (!isset($org)) {
			$msg .= " {$orgMember->org_id} is being tracked, and will be ".
				're-added if they are still in this org. In order to permanently '.
				"remove {$name} from the tracklist, you might need to remove ".
				"the org ID {$orgMember->org_id} ";
		} else {
			$orgName = $org->faction->inColor($org->name);
			$msg .= " {$orgName} is being tracked. {$name} will be re-added ".
				'to the tracking list during the next tracker roster update, '.
				'unless they have left this org. '.
				"In order to permanently remove {$name} from the ".
				"tracklist, you might have to remove the whole org {$orgName}".
				" (ID {$org->id}) ";
		}

		$msg .= "from the tracker with <highlight><symbol>track remorg {$orgMember->org_id}<end>.";
		$context->reply($msg);
	}

	/** Add a player to the track list */
	#[NCA\HandlesCommand('track')]
	#[NCA\Help\Epilogue(
		"Tracked characters are announced via the source 'system(tracker)'\n".
		"Make sure you have routes in place to display these messages\n".
		"where you want to see them. See <a href='chatcmd:///tell <myname> help route'><symbol>help route</a> for more information."
	)]
	public function trackAddCommand(
		CmdContext $context,
		#[Str('add')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if (!isset($uid)) {
			$msg = "Character <highlight>{$char}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		if (!$this->trackUid($uid, $char(), $context->char->name)) {
			$msg = "Character <highlight>{$char}<end> is already on the track list.";
			$context->reply($msg);
			return;
		}
		$msg = "Character <highlight>{$char}<end> has been added to the track list.";

		$context->reply($msg);
	}

	/** Add a whole organization to the track list */
	#[NCA\HandlesCommand('track')]
	public function trackAddOrgIdCommand(
		CmdContext $context,
		#[Str('addorg')] string $action,
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

		if ($this->db->table(TrackingOrg::getTable())->where('org_id', $orgId)->exists()) {
			$msg = "The org {$org->faction->inColor($org->name)} is already being tracked.";
			$context->reply($msg);
			return;
		}
		$this->db->insert(new TrackingOrg(
			org_id: $orgId,
			added_by: $context->char->name,
		));
		$context->reply("Adding {$org->faction->inColor($org->name)} to the tracker.");
		try {
			$guild = $this->guildManager->byId($orgId, $this->config->main->dimension, true);
			if (!isset($guild)) {
				$context->reply("No data found for {$org->faction->inColor($org->name)}.");
				return;
			}
			$this->updateRosterForOrg($guild);
		} catch (Throwable $e) {
			$this->logger->error('{error}', [
				'error' => $e->getMessage(),
				'exception' => $e->getPrevious(),
			]);
			$context->reply($e->getMessage());
			return;
		}
		$context->reply(
			"Added all members of {$org->faction->inColor($org->name)} to the roster."
		);
	}

	/** Add a whole organization to the track list */
	#[NCA\HandlesCommand('track')]
	public function trackAddOrgNameCommand(
		CmdContext $context,
		#[Str('addorg')] string $action,
		#[NonNumberStr] string $orgName,
	): void {
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		$orgs = new Collection($this->findOrgController->lookupOrg($orgName));
		$count = $orgs->count();
		if ($count === 0) {
			$context->reply('No matches found.');
			return;
		}
		$blob = $this->formatOrglist(...$orgs->toArray());
		$msg = Text::makeBlob("Org Search Results for '{$orgName}' ({$count})", $blob);
		$context->reply($msg);
	}

	public function formatOrglist(Organization ...$organizations): string {
		$orgs = (new Collection($organizations))->sortBy('name');
		$blob = "<header2>Matching orgs<end>\n";
		foreach ($orgs as $org) {
			$addLink = Text::makeChatcmd('track', "/tell <myname> track addorg {$org->id}");
			$blob .= "<tab>{$org->name} ({$org->faction->inColor()}), ".
				"ID {$org->id} - <highlight>{$org->num_members}<end> members ".
				"[{$addLink}]\n";
		}
		return $blob;
	}

	/** Remove an organization from the track list */
	#[NCA\HandlesCommand('track')]
	public function trackRemOrgCommand(
		CmdContext $context,
		#[Regexp('(?:rem|del)org', example: 'remorg')] string $action,
		int $orgId
	): void {
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		$org = $this->findOrgController->getByID($orgId);

		if ($this->db->table(TrackingOrg::getTable())->where('org_id', $orgId)->doesntExist()) {
			$msg = "The org <highlight>#{$orgId}<end> is not being tracked.";
			if (isset($org)) {
				$msg = "The org {$org->faction->inColor($org->name)} is not being tracked.";
			}
			$context->reply($msg);
			return;
		}
		$this->db->table(TrackingOrgMember::getTable())
			->where('org_id', $orgId)
			->asObj(TrackingOrgMember::class)
			->each(function (TrackingOrgMember $exMember): void {
				$this->buddylistManager->removeId($exMember->uid, static::REASON_ORG_TRACKER);
			});
		$this->db->table(TrackingOrgMember::getTable())
			->where('org_id', $orgId)
			->delete();
		$this->db->table(TrackingOrg::getTable())
			->where('org_id', $orgId)
			->delete();
		$msg = "The org <highlight>#{$orgId}<end> is no longer being tracked.";
		if (isset($org)) {
			$msg = "The org {$org->faction->inColor($org->name)} is no longer being tracked.";
		}
		$context->reply($msg);
	}

	/** List the organizations on the track list */
	#[NCA\HandlesCommand('track')]
	public function trackListOrgsCommand(
		CmdContext $context,
		#[Regexp('orgs?', example: 'orgs')] string $action,
		#[Str('list')] ?string $subAction
	): void {
		$orgs = $this->db->table(TrackingOrg::getTable())
			->asObj(TrackingOrg::class);
		$orgIds = $orgs->whereNotNull('org_id')->pluckInts('org_id')->toList();
		$orgsByID = $this->findOrgController->getOrgsById(...$orgIds)
			->keyByInt('id');
		$orgs = $orgs->each(static function (TrackingOrg $o) use ($orgsByID): void {
			$o->org = $orgsByID->get($o->org_id);
		})->sort(static function (TrackingOrg $o1, TrackingOrg $o2): int {
			return strcasecmp($o1->org->name??'', $o2->org->name??'');
		});

		$lines = $orgs->map(static function (TrackingOrg $o): ?string {
			if (!isset($o->org)) {
				return null;
			}
			$delLink = Text::makeChatcmd('remove', "/tell <myname> track remorg {$o->org->id}");
			return "<tab>{$o->org->name} ({$o->org->faction->inColor()}) - ".
				"<highlight>{$o->org->num_members}<end> members, added by <highlight>{$o->added_by}<end> ".
				"[{$delLink}]";
		})->filter();
		if ($lines->isEmpty()) {
			$context->reply('There are currently no orgs being tracked.');
			return;
		}
		$blob = "<header2>Orgs being tracked<end>\n".
			$lines->join("\n");
		$msg = Text::makeBlob('Tracked orgs(' . $lines->count() . ')', $blob);
		$context->reply($msg);
	}

	/**
	 * Show a nice online list of everyone on your track list
	 * You can use any combination of filters to narrow down the list:
	 * - clan|neutral|omni
	 * - &lt;profession&gt;
	 * - tl1|tl2|tl3|tl4|tl5|tl6|tl7
	 * - &lt;level&gt;|&lt;min level&gt;-|-&lt;max level&gt;|&lt;min level&gt;-&lt;max level&gt;
	 *
	 * By default, this will not show chars hidden via '<symbol>track hide', unless you give 'all'
	 * To get links for removing and hiding/unhiding characters, add '--edit'
	 */
	#[NCA\HandlesCommand('track')]
	#[NCA\Help\Example('<symbol>track online')]
	#[NCA\Help\Example('<symbol>track online doc')]
	#[NCA\Help\Example('<symbol>track online clan doc crat tl2-4')]
	#[NCA\Help\Example('<symbol>track all --edit')]
	public function trackOnlineCommand(
		CmdContext $context,
		#[Str('online')] string $action,
		?string $filter,
	): bool {
		$filters = [];
		if (isset($filter)) {
			$parser = new TrackerOnlineParser();
			try {
				$options = $parser->parse(strtolower($filter));
			} catch (TrackerOnlineParserException $e) {
				$context->reply($e->getMessage());
				return true;
			} catch (Exception $e) {
				$context->reply($e->getMessage());
				return true;
			}
			foreach ($options as $option) {
				$filters[$option->type] ??= [];
				$filters[$option->type] []= $option->value;
			}
		}
		$hiddenChars = $this->db->table(TrackingOrgMember::getTable())
			->select('name')
			->where('hidden', true)
			->union(
				$this->db->table(TrackedUser::getTable())
					->select('name')
					->where('hidden', true)
			)->pluckStrings('name')
			->unique()
			->mapToDictionary(static fn (string $s): array => [$s => true])
			->toArray();
		$data1 = $this->db->table(TrackingOrgMember::getTable())->select('name');
		$data2 = $this->db->table(TrackedUser::getTable())->select('name');
		if (!isset($filters['all'])) {
			$data1->where('hidden', false);
			$data2->where('hidden', false);
		}
		$trackedUsers = $data1
			->union($data2)
			->pluckStrings('name')
			->unique()
			->filter(function (string $name): bool {
				return $this->buddylistManager->isOnline($name) ?? false;
			})
			->toArray();

		/** @var Collection<int,OnlineTrackedUser> */
		$data = $this->playerManager->searchByNames($this->config->main->dimension, ...$trackedUsers)
			->sortBy('name')
			->map(static function (Player $p) use ($hiddenChars): OnlineTrackedUser {
				$op = OnlineTrackedUser::fromPlayer($p);
				$op->pmain ??= $op->name;
				$op->online = true;
				$op->hidden = isset($hiddenChars[$op->name]);
				return $op;
			});
		$data = $this->filterOnlineList($data, $filters);
		$hasFilters = array_diff(array_keys($filters), ['all', 'edit']);
		if ($data->isEmpty()) {
			if ($hasFilters) {
				$context->reply('No tracked players matching your filter are currently online.');
			} else {
				$context->reply('No tracked players are currently online.');
			}
			return true;
		}
		$blob = $this->renderOnlineList($data->toList(), isset($filters['edit']));
		$footNotes = [];
		if (!isset($filters['all'])) {
			$allLink = Text::makeChatcmd(
				"<symbol>{$context->message} all",
				"/tell <myname> {$context->message} all"
			);
			$footNotes []= "<i>Use {$allLink} to see hidden characters.</i>";
		}
		if (!isset($filters['edit'])) {
			$editLink = Text::makeChatcmd(
				"<symbol>{$context->message} --edit",
				"/tell <myname> {$context->message} --edit"
			);
			$footNotes []= "<i>Use {$editLink} to see more options.</i>";
		}
		if (count($footNotes) > 0) {
			$blob .= "\n\n" . implode("\n", $footNotes);
		}
		if ($hasFilters) {
			$msg = Text::makeBlob('Online tracked players matching your filter (' . count($data). ')', $blob);
		} else {
			$msg = Text::makeBlob('Online tracked players (' . $data->count(). ')', $blob);
		}
		$context->reply($msg);
		return true;
	}

	/**
	 * Get the blob with details about the tracked players currently online
	 *
	 * @param list<OnlineTrackedUser> $players
	 *
	 * @return string The blob
	 */
	public function renderOnlineList(array $players, bool $edit): string {
		$groupBy = $this->trackerGroupBy;
		$groups = [];
		if ($groupBy === static::GROUP_TL) {
			foreach ($players as $player) {
				$tl = TitleLevel::fromLevel($player->level ?? 1)->value;
				$groups[$tl] ??= (object)[
					'title' => 'TL'.$tl,
					'members' => [],
					'sort' => $tl,
				];
				$groups[$tl]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_PROF) {
			foreach ($players as $player) {
				$prof = $player->profession->value ?? 'Unknown';
				$profIcon = $player->profession?->toIcon() ?? '?';
				$groups[$prof] ??= (object)[
					'title' => $profIcon . ' ' . $prof,
					'members' => [],
					'sort' => $prof,
				];
				$groups[$prof]->members []= $player;
			}
		} elseif ($groupBy === static::GROUP_FACTION) {
			foreach ($players as $player) {
				$faction = $player->faction->value;
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
			$groups['all'] = (object)[
				'title' => 'All tracked players',
				'members' => $players,
				'sort' => 0,
			];
		}
		usort($groups, static function (object $a, object $b): int {
			return $a->sort <=> $b->sort;
		});
		$parts = [];
		foreach ($groups as $group) {
			$parts []= "<header2>{$group->title} (" . count($group->members) . ")<end>\n".
				$this->renderPlayerGroup($group->members, $groupBy, $edit);
		}

		return implode("\n\n", $parts);
	}

	/**
	 * Return the content of the online list for one player group
	 *
	 * @param iterable<int,OnlineTrackedUser> $players The list of players in that group
	 *
	 * @return string The blob for this group
	 */
	public function renderPlayerGroup(iterable $players, int $groupBy, bool $edit): string {
		$players = (new Collection($players))->sort(
			static function (OnlineTrackedUser $p1, OnlineTrackedUser $p2): int {
				return strnatcmp($p1->name, $p2->name);
			}
		)->map(function (OnlineTrackedUser $player) use ($groupBy, $edit): string {
			return $this->renderPlayerLine($player, $groupBy, $edit);
		});
		return '<tab>' . $players->join("\n<tab>");
	}

	/**
	 * Render a single online-line of a player
	 *
	 * @param OnlineTrackedUser $player  The player to render
	 * @param int               $groupBy Which grouping method to use. When grouping by prof, we don't show the prof icon
	 *
	 * @return string A single like without newlines
	 */
	public function renderPlayerLine(OnlineTrackedUser $player, int $groupBy, bool $edit): string {
		$blob = '';
		if ($groupBy !== static::GROUP_PROF) {
			$blob .= ($player->profession?->toIcon() ?? '?') . ' ';
		}
		if ($this->trackerUseFactionColor) {
			$blob .= $player->faction->inColor($player->name);
		} else {
			$blob .= "<highlight>{$player->name}<end>";
		}
		$prof = $player->profession?->short() ?? 'Unknown';
		$blob .= " ({$player->level}/<green>{$player->ai_level}<end>, {$prof})";
		if ($player->guild !== null && $player->guild !== '') {
			$blob .= ' :: ' . $player->faction->inColor($player->guild) . " ({$player->guild_rank})";
		}
		if ($edit) {
			$historyLink = Text::makeChatcmd('history', "/tell <myname> track show {$player->name}");
			$removeLink = Text::makeChatcmd('untrack', "/tell <myname> track rem {$player->charid}");
			$hideLink = Text::makeChatcmd('hide', "/tell <myname> track hide {$player->charid}");
			$unhideLink = Text::makeChatcmd('unhide', "/tell <myname> track unhide {$player->charid}");
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
	#[NCA\HandlesCommand('track')]
	public function trackHideUidCommand(
		CmdContext $context,
		#[Str('hide')] string $action,
		int $uid
	): void {
		$name = $this->chatBot->getName($uid);
		$this->trackHideCommand($context, $name ?? "UID {$uid}", $uid);
	}

	/** Hide a character from the '<symbol>track online' list */
	#[NCA\HandlesCommand('track')]
	public function trackHideNameCommand(
		CmdContext $context,
		#[Str('hide')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if (!isset($uid)) {
			$msg = "Character <highlight>{$char}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$this->trackHideCommand($context, $char(), $uid);
	}

	public function trackHideCommand(CmdContext $context, string $name, int $uid): void {
		$updated = $this->db->table(TrackedUser::getTable())
			->where('uid', $uid)
			->update(['hidden' => true]);
		if ($updated === 0) {
			$updated = $this->db->table(TrackingOrgMember::getTable())
			->where('uid', $uid)
			->update(['hidden' => true]);
		}
		if ($updated === 0) {
			$msg = "<highlight>{$name}<end> is not tracked.";
			$context->reply($msg);
			return;
		}
		$msg = "<highlight>{$name}<end> is no longer shown in <highlight><symbol>track online<end>.";
		$context->reply($msg);
	}

	/** Show a hidden a character on the '<symbol>track online' list again */
	#[NCA\HandlesCommand('track')]
	public function trackUnhideUidCommand(
		CmdContext $context,
		#[Str('unhide')] string $action,
		int $uid
	): void {
		$name = $this->chatBot->getName($uid);
		$this->trackUnhideCommand($context, $name ?? "UID {$uid}", $uid);
	}

	/** Show a hidden a character on the '<symbol>track online' list again */
	#[NCA\HandlesCommand('track')]
	public function trackUnhideNameCommand(
		CmdContext $context,
		#[Str('unhide')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if (!isset($uid)) {
			$msg = "Character <highlight>{$char}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$this->trackUnhideCommand($context, $char(), $uid);
	}

	public function trackUnhideCommand(CmdContext $context, string $name, int $uid): void {
		$updated = $this->db->table(TrackedUser::getTable())
			->where('uid', $uid)
			->update(['hidden' => false]);
		if ($updated === 0) {
			$updated = $this->db->table(TrackingOrgMember::getTable())
				->where('uid', $uid)
				->update(['hidden' => false]);
		}
		if ($updated === 0) {
			$msg = "<highlight>{$name}<end> is not tracked.";
			$context->reply($msg);
			return;
		}
		$msg = "<highlight>{$name}<end> is now shown in <highlight><symbol>track online<end> again.";
		$context->reply($msg);
	}

	/** See the track history of a given character */
	#[NCA\HandlesCommand('track')]
	public function trackShowCommand(
		CmdContext $context,
		#[Str('show', 'view')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if (!isset($uid)) {
			$msg = "<highlight>{$char}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$orgMember = null;

		$user = $this->db->table(TrackedUser::getTable())
			->where('uid', $uid)
			->firstObj(TrackedUser::class);

		if ($user === null) {
			$orgMember = $this->db->table(TrackingOrgMember::getTable())
				->where('uid', $uid)
				->firstObj(TrackingOrgMember::class);
			if ($orgMember === null) {
				$msg = "<highlight>{$char}<end> is not being tracked.";
				$context->reply($msg);
				return;
			}
			$hidden = $orgMember->hidden;
		} else {
			$hidden = $user->hidden;
		}

		$events = $this->db->table(Tracking::getTable())
			->where('uid', $uid)
			->orderByDesc('id')
			->select(['event', 'dt'])
			->asObj(Tracking::class);
		$hideLink = Text::makeChatcmd(
			'hide',
			"/tell <myname> track hide {$uid}"
		);
		if ($hidden) {
			$hideLink = Text::makeChatcmd(
				'unhide',
				"/tell <myname> track unhide {$uid}"
			);
		}
		$blob = "<header2>Info<end>\n".
			"<tab>Name: <highlight>{$char}<end>\n".
			"<tab>Uid: <highlight>{$uid}<end>\n";
		if (isset($user)) {
			$blob .= "<tab>Added: By <highlight>{$user->added_by}<end> on ".
				'<highlight>' . Util::date($user->added_dt) . "<end>\n";
		}
		$blob .= '<tab>Visible: '.
			($hidden ? '<off>no<end>' : '<on>yes<end>').
			" [{$hideLink}]\n\n".
			"<header2>All events for {$char}<end>\n";
		if ($events->isEmpty()) {
			$blob .= "<tab><highlight>{$char}<end> has never logged on.";
		}
		foreach ($events as $event) {
			if ($event->event === 'logon') {
				$status = '<on>logon<end>';
			} elseif ($event->event === 'logoff') {
				$status = '<off>logoff<end>';
			} else {
				$status = '<grey>unknown<end>';
			}
			$blob .= "<tab> {$status} - " . Util::date($event->dt) ."\n";
		}

		$msg = Text::makeBlob("Track History for {$char}", $blob);
		$context->reply($msg);
	}

	protected function trackUid(int $uid, string $name, ?string $sender=null): bool {
		if ($this->db->table(TrackedUser::getTable())->where('uid', $uid)->exists()) {
			return false;
		}
		$this->db->insert(new TrackedUser(
			name: $name,
			uid: $uid,
			added_by: $sender ?? $this->config->main->character,
			added_dt: time(),
		));
		$this->buddylistManager->addId($uid, static::REASON_TRACKER);
		return true;
	}

	/** Check if $uid has logged in too long ago and un-track if so  */
	private function untrackIfTooOld(int $uid, int $age): void {
		if ($age <= $this->trackerAutoUntrack) {
			return;
		}
		$this->logger->notice("UID {uid} hasn't logged in for {duration} - untracking", [
			'uid' => $uid,
			'duration' => Util::unixtimeToReadable($age),
		]);
		$deleted = $this->db->table(TrackedUser::getTable())
			->where('uid', $uid)
			->delete();
		if ($deleted) {
			$this->buddylistManager->removeId($uid, static::REASON_TRACKER);
			$this->db->table(Tracking::getTable())->where('uid', $uid)->delete();
		}
	}

	private function updateRosterForOrg(?Guild $org): void {
		// Check if JSON file was downloaded properly
		if ($org === null) {
			throw new Exception('Error downloading the guild roster JSON file');
		}

		if (count($org->members) === 0) {
			$this->logger->error('The organisation {org_name} has no members. Not changing its roster', [
				'org_name' => $org->orgname,
			]);
			return;
		}

		// Save the current members in a hash for easy access
		$oldMembers = $this->db->table(TrackingOrgMember::getTable())
			->where('org_id', $org->guild_id)
			->asObj(TrackingOrgMember::class)
			->keyByInt('uid');
		$this->db->awaitBeginTransaction();
		$toInsert = [];
		$toInit = [];
		try {
			foreach ($org->members as $member) {
				$oldMember = $oldMembers->get($member->charid);
				if (isset($oldMember) && $oldMember->name === $member->name) {
					$oldMembers->forget((string)$oldMember->uid);
					continue;
				}
				if (isset($oldMember)) {
					$this->db->table(TrackingOrgMember::getTable())
						->where('uid', $oldMember->uid)
						->update(['name' => $member->name]);
					$oldMembers->forget((string)$oldMember->uid);
				} else {
					$toInsert []= [
						'org_id' => $org->guild_id,
						'uid' => $member->charid,
						'name' => $member->name,
					];
					$toInit []= [
						'id' => Uuid::uuid7(),
						'uid' => $member->charid,
						'dt' => time(),
						'event' => 'logoff',
					];
				}
			}
			if (count($toInsert)) {
				$maxBuddies = $this->chatBot->getBuddyListSize();
				$numBuddies = $this->buddylistManager->getUsedBuddySlots();
				if (count($toInsert) + $numBuddies > $maxBuddies) {
					$this->db->rollback();
					$this->db->table(TrackingOrg::getTable())->where('org_id', $org->guild_id)->delete();
					throw new Exception(
						'You cannot add ' . count($toInsert) . ' more '.
						'characters to the tracking list, you only have '.
						($maxBuddies - $numBuddies) . ' slots left. Please '.
						'install aochatproxy, or add more characters to your '.
						'existing configuration.'
					);
				}
				$this->logger->info('Adding {count} new orgmember(s) of {orgname} to tracker', [
					'count' => count($toInsert),
					'orgname' => $org->orgname,
				]);
				$this->db->table(TrackingOrgMember::getTable())
					->chunkInsert($toInsert);
				$this->db->table(Tracking::getTable())
					->chunkInsert($toInit);
				foreach ($toInsert as $buddy) {
					$this->logger->info('Adding {name} ({uid}) to tracker', [
						'name' => $buddy['name'],
						'uid' => $buddy['uid'],
					]);
					$this->chatBot->cacheUidNameMapping($buddy['name'], $buddy['uid']);
					$this->buddylistManager->addId($buddy['uid'], static::REASON_ORG_TRACKER);
				}
			}
			$this->logger->info('Removing {count} ex orgmember(s) of {orgname} from tracker', [
				'count' => $oldMembers->count(),
				'orgname' => $org->orgname,
			]);
			$oldMembers->each(function (TrackingOrgMember $exMember): void {
				$this->logger->info('Removing {name} ({uid}) from tracker', [
					'name' => $exMember->name,
					'uid' => $exMember->uid,
				]);
				$this->buddylistManager->removeId($exMember->uid, static::REASON_ORG_TRACKER);
				$this->db->table(TrackingOrgMember::getTable())
					->where('uid', $exMember->uid)
					->where('org_id', $exMember->org_id)
					->delete();
			});
		} catch (Throwable $e) {
			$this->db->rollback();
			throw new Exception("Error adding org members for {$org->orgname}: " . $e->getMessage(), 0, $e);
		}
		$this->db->commit();
	}

	/**
	 * @param Collection<int,OnlineTrackedUser> $data
	 * @param array<string,list<string>>        $filters
	 *
	 * @return Collection<int,OnlineTrackedUser>
	 */
	private function filterOnlineList(Collection $data, array $filters): Collection {
		if (isset($filters['profession'])) {
			$professions = [];
			foreach ($filters['profession'] as $prof) {
				$professions []= Profession::fromName($prof)->value;
			}
			$data = $data->whereIn('profession', $professions);
		}
		if (isset($filters['faction'])) {
			$factions = [];
			foreach ($filters['faction'] as $faction) {
				$factions []= Faction::fromName($faction)->value;
			}
			$data = $data->whereIn('faction', $factions);
		}
		if (isset($filters['titleLevelRange'])) {
			$filters['levelRange'] ??= [];
			foreach ($filters['titleLevelRange'] as $range) {
				$from = TitleLevel::from((int)substr($range, 2, 1))->toLevelRange();
				$to = TitleLevel::from((int)substr($range, 4, 1))->toLevelRange();
				$filters['levelRange'] []= "{$from->min}-{$to->max}";
			}
		}
		if (isset($filters['titleLevel'])) {
			$filters['levelRange'] ??= [];
			foreach ($filters['titleLevel'] as $tl) {
				$range = TitleLevel::from((int)substr($tl, 2))->toLevelRange();
				$filters['levelRange'] []= "{$range->min}-{$range->max}";
			}
		}
		if (isset($filters['level'])) {
			$filters['levelRange'] ??= [];
			foreach ($filters['level'] as $level) {
				$filters['levelRange'] []= "{$level}-{$level}";
			}
		}
		if (isset($filters['levelRange'])) {
			$ranges = [];
			foreach ($filters['levelRange'] as $range) {
				[$min, $max] = Safe::pregSplit("/\s*-\s*/", $range);
				$ranges []= [strlen($min) ? (int)$min : 1, strlen($max) ? (int)$max : 220];
			}
			$data = $data->filter(static function (OnlineTrackedUser $user) use ($ranges): bool {
				if (!isset($user->level)) {
					return true;
				}
				foreach ($ranges as $range) {
					if ($user->level >= $range[0] && $user->level <= $range[1]) {
						return true;
					}
				}
				return false;
			});
		}
		return $data;
	}
}
