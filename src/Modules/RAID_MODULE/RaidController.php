<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use function Amp\async;
use function Amp\Future\await;

use Amp\Pipeline\Pipeline;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\Events\{MyPrivateChannelMsgEvent, SendPrivEvent};
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	EventManager,
	MessageHub,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PNonGreedy,
	ParamClass\PWord,
	Registry,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
	Util,
};
use Nadybot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController;

use Nadybot\Modules\{
	BASIC_CHAT_MODULE\ChatAssistController,
	COMMENT_MODULE\CommentCategory,
	COMMENT_MODULE\CommentController,
	WEBSERVER_MODULE\StatsController,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Safe\DateTimeImmutable;

/**
 * This class contains all functions necessary to start, stop and resume a raid
 *
 * @package Nadybot\Modules\POINT_RAID_MODULE
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Raid'),
	NCA\DefineCommand(
		command: 'raid',
		accessLevel: 'all',
		description: 'Check if the raid is running',
	),
	NCA\DefineCommand(
		command: RaidController::CMD_RAID_MANAGE,
		accessLevel: 'raid_leader_1',
		description: 'Everything to run a points raid',
	),
	NCA\DefineCommand(
		command: RaidController::CMD_RAID_TICKER,
		accessLevel: 'raid_leader_2',
		description: 'Change the raid points ticker',
	),

	NCA\ProvidesEvent(RaidStartEvent::class),
	NCA\ProvidesEvent(RaidStopEvent::class),
	NCA\ProvidesEvent(RaidChangeEvent::class),
	NCA\ProvidesEvent(RaidLockEvent::class),
	NCA\ProvidesEvent(RaidUnlockEvent::class),

	NCA\EmitsMessages('raid', 'announce'),
	NCA\EmitsMessages('raid', 'lock'),
	NCA\EmitsMessages('raid', 'unlock'),
	NCA\EmitsMessages('raid', 'start'),
	NCA\EmitsMessages('raid', 'stop'),
]
class RaidController extends ModuleInstance {
	public const CMD_RAID_MANAGE = 'raid manage';
	public const CMD_RAID_TICKER = 'raid change ticker';

	public const ERR_NO_RAID = "There's currently no raid running.";
	public const CAT_RAID = 'raid';

	/** Announce the raid periodically */
	#[NCA\Setting\Boolean(accessLevel: 'raid_admin_2')]
	public bool $raidAnnouncement = true;

	/** Announcement interval */
	#[NCA\Setting\Time(
		options: ['30s', '60s', '90s', '120s', '150s', '180s'],
		accessLevel: 'raid_admin_2',
	)]
	public int $raidAnnouncementInterval = 90;

	/** Give raid points based on duration of participation */
	#[NCA\Setting\Boolean(accessLevel: 'raid_admin_2')]
	public bool $raidPointsForTime = false;

	/** Start ticker-based raids with the ticker paused */
	#[NCA\Setting\Boolean(accessLevel: 'raid_admin_2')]
	public bool $raidTickerStartPaused = false;

	/** Point rate, in seconds */
	#[NCA\Setting\Time(accessLevel: 'raid_admin_2')]
	public int $raidPointsInterval = 5 * 60; // 5 mins

	/** Add raid initiator to the raid */
	#[NCA\Setting\Boolean(accessLevel: 'raid_admin_2')]
	public bool $raidAutoAddCreator = true;

	/** Stopping the raid clears the callers */
	#[NCA\Setting\Boolean(accessLevel: 'raid_admin_2')]
	public bool $raidStopClearsCallers = false;

	/** Locking the raid kicks players not in the raid */
	#[NCA\Setting\Options(
		options: [
			'Kick everyone not in the raid' => 2,
			"Kick all, except those who've been in the raid before" => 1,
			"Don't kick on raid lock" => 0,
		],
		accessLevel: 'raid_admin_2',
	)]
	public int $raidKickNotinOnLock = 0;

	/** Time after which non-raiding bot-members are removed from the bot */
	#[NCA\Setting\TimeOrOff(
		options: ['off', '30d', '90d', '1y'],
		accessLevel: 'mod',
	)]
	public int $raidDemoteMembersInterval = 0;

	/** The currently running raid or null if none running */
	public ?Raid $raid = null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private RaidMemberController $raidMemberController;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private CommentController $commentController;

	#[NCA\Inject]
	private RaidPointsController $raidPointsController;

	#[NCA\Inject]
	private ChatAssistController $chatAssistController;

	#[NCA\Inject]
	private StatsController $statsController;

	#[NCA\Inject]
	private PrivateChannelController $privateChannelController;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Setup]
	public function setup(): void {
		EventLoop::queue($this->resumeRaid(...));
		$stateStats = new RaidStateStats();
		Registry::injectDependencies($stateStats);
		$this->statsController->registerProvider($stateStats, 'states');
		$stateLockStats = new RaidLockStats();
		Registry::injectDependencies($stateLockStats);
		$this->statsController->registerProvider($stateLockStats, 'states');
		$raidStats = new RaidMemberStats('raid');
		Registry::injectDependencies($raidStats);
		$this->statsController->registerDataset($raidStats, 'raid');
	}

	public function getRaidCategory(): CommentCategory {
		$raidCat = $this->commentController->getCategory(static::CAT_RAID);
		if ($raidCat !== null) {
			return $raidCat;
		}
		$raidCat = new CommentCategory(
			name: static::CAT_RAID,
			created_by: $this->config->main->character,
			min_al_read: 'raid_leader_1',
			min_al_write: 'raid_leader_2',
			user_managed: false,
		);
		$this->commentController->saveCategory($raidCat);
		return $raidCat;
	}

	/** Get the content of the popup that shows you how to join the raid */
	public function getRaidJoinLink(): string {
		if (!isset($this->raid)) {
			return '';
		}
		$numRaiders = $this->raid->numActiveRaiders();
		if ($this->raid->locked) {
			$status = '<off>locked<end>';
		} elseif (isset($this->raid->max_members) && $this->raid->max_members > 0 && $this->raid->max_members <= $numRaiders) {
			$status = '<off>full<end>';
		} else {
			$status = '<on>open<end>';
		}
		$blob = "<header2>Current raid<end>\n".
			"<tab>Description: <highlight>{$this->raid->description}<end>\n".
			'<tab>Duration: running for <highlight>'.
			Util::unixtimeToReadable(time() - $this->raid->started) . "<end>.\n".
			"<tab>Raiders: <highlight>{$numRaiders}<end>".
			((isset($this->raid->max_members) && $this->raid->max_members > 0) ? "/<highlight>{$this->raid->max_members}<end>" : '').
			"\n".
			"<tab>Status: {$status}\n";
		if ($this->raid->seconds_per_point > 0) {
			$blob .= '<tab>Points: <highlight>1 raid point every '.
				Util::unixtimeToReadable($this->raid->seconds_per_point);
			if ($this->raid->ticker_paused) {
				$blob .= ' (<red>paused<end>)';
			} else {
				$blob .= ' (<green>ticking<end>)';
			}
			if ($this->raidPointsController->raidTickerRequiresLock && !$this->raid->locked) {
				$blob .= ' (<red>not locked<end>)';
			}
			$sppCmd = Text::makeChatcmd('pause', '/tell <myname> raid spp pause');
			if ($this->raid->ticker_paused) {
				$sppCmd = Text::makeChatcmd('resume', '/tell <myname> raid spp resume');
			}
			$blob .= " [{$sppCmd}]\n";
		} else {
			$blob .= "<tab>Points: <highlight>Given for each kill by the raid leader(s)<end>\n";
		}
		$blob .= "\n[".
			Text::makeChatcmd('join', '/tell <myname> raid join').
			'] / ['.
			Text::makeChatcmd('leave', '/tell <myname> raid leave').
			'] the raid.';
		$blob .= "\n\n[".
			Text::makeChatcmd('go lft', '/lft <myname>').
			']';
		return $blob;
	}

	public function getControlInterface(): string {
		if (!isset($this->raid)) {
			return '';
		}
		$blob = "<header2>Raid Control Interface<end>\n".
			'<tab>Raid Status: Running for <highlight>'.
			Util::unixtimeToReadable(time() - $this->raid->started) . '<end>'.
			' [' . Text::makeChatcmd('stop', '/tell <myname> raid stop') . "]\n".
			'<tab>Points Status: ';
		if ($this->raid->seconds_per_point > 0) {
			$blob .= '<highlight>1 point every '.
				Util::unixtimeToReadable($this->raid->seconds_per_point).
				"<end>\n";
		} else {
			$sppDefault = $this->raidPointsInterval;
			$blob .= '<highlight>Given by the raid leader(s)<end>';
			if ($sppDefault > 0) {
				$blob .= ' ['.
					Text::makeChatcmd(
						'enable ticker',
						"/tell <myname> raid spp {$sppDefault}s"
					).
					']';
			}
			$blob .= "\n";
		}
		$numRaiders = $this->raid->numActiveRaiders();
		$blob .=  "<tab>Raiders: <highlight>{$numRaiders}<end>";
		if (isset($this->raid->max_members) && $this->raid->max_members > 0) {
			$blob .= "/<highlight>{$this->raid->max_members}<end>";
			$blob .= ' [' . Text::makeChatcmd(
				'remove limit',
				'/tell <myname> raid limit off'
			) . ']';
		} else {
			foreach ([12, 24, 36] as $limit) {
				$blob .= ' [' . Text::makeChatcmd(
					"limit to {$limit}",
					"/tell <myname> raid limit {$limit}"
				) . ']';
			}
		}
		$blob .= "\n";
		$blob .= '<tab>Raid State: <highlight>';
		if ($this->raid->locked) {
			$blob .= 'locked<end> ['.
				Text::makeChatcmd('Unlock', '/tell <myname> raid unlock').
				"]\n";
		} elseif (isset($this->raid->max_members) && $this->raid->max_members > 0 && $numRaiders >= $this->raid->max_members) {
			$blob .= 'full<end> ['.
				Text::makeChatcmd('remove limit', '/tell <myname> raid limit off').
				"]\n";
		} else {
			$blob .= 'open<end> ['.
				Text::makeChatcmd('lock', '/tell <myname> raid lock').
				"]\n";
		}
		$blob .= "<tab>Description: <highlight>{$this->raid->description}<end>\n";
		$blob .= '<tab>Raid announcement: <highlight>';
		if ($this->raid->announce_interval === 0) {
			$blob .= 'off<end> ['.
				Text::makeChatcmd(
					'enable',
					'/tell <myname> raid announce '.
					$this->raidAnnouncementInterval
				).
				"]\n";
		} else {
			$interval = Util::unixtimeToReadable($this->raid->announce_interval);
			$blob .= "every {$interval}<end> [".
				Text::makeChatcmd(
					'disable',
					'/tell <myname> raid announce off'
				).
				"]\n";
		}
		return $blob;
	}

	/** Show if a raid is currently running, with a link to join */
	#[NCA\HandlesCommand('raid')]
	public function raidCommand(CmdContext $context): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$canAdminRaid = $this->commandManager->couldRunCommand($context, 'raid start test');
		if ($canAdminRaid) {
			$this->chatBot->sendTell(
				$this->text->makeBlob('Raid Control', $this->getControlInterface()),
				$context->char->name
			);
		}
		$msg = ((array)$this->text->makeBlob('click to join', $this->getRaidJoinLink(), 'Raid information'))[0];
		$announceMsg = $this->raid->getAnnounceMessage($msg);
		$context->reply($announceMsg);
	}

	/** Try to resume a raid that was already running when the bot shut down */
	public function resumeRaid(): void {
		/** @var ?Raid */
		$lastRaid = $this->db->table(Raid::getTable())
			->orderByDesc('raid_id')
			->limit(1)
			->asObj(Raid::class)
			->first();
		if ($lastRaid === null || (int)$lastRaid->stopped > 0) {
			return;
		}

		/** @var ?RaidLog */
		$lastRaidLog = $this->db->table(RaidLog::getTable())
			->where('raid_id', $lastRaid->raid_id)
			->orderByDesc('time')
			->limit(1)
			->asObj(RaidLog::class)
			->first();
		if ($lastRaidLog) {
			foreach (get_object_vars($lastRaidLog) as $key => $value) {
				if (property_exists($lastRaid, $key)) {
					$lastRaid->{$key} = $value;
				}
			}
		}
		$this->startRaid($lastRaid);
		$this->raidMemberController->resumeRaid($lastRaid);
	}

	/** Start a raid with a given description */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidStartWithLimitsCommand(
		CmdContext $context,
		#[NCA\Str('start', 'run', 'create')] string $action,
		PNonGreedy $description,
		#[NCA\Str('limit')] string $subAction,
		int $maxMembers,
	): void {
		$raid = new Raid(
			started_by: $context->char->name,
			max_members: $maxMembers,
			description: $description(),
			announce_interval: $this->raidAnnouncement ? $this->raidAnnouncementInterval : 0,
			seconds_per_point: ($this->raidPointsForTime) ? $this->raidPointsInterval : 0,
			ticker_paused: $this->raidTickerStartPaused,
		);
		$this->startNewRaid($context, $raid);
	}

	/** Start a raid with a given description */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidStartCommand(
		CmdContext $context,
		#[NCA\Str('start', 'run', 'create')] string $action,
		string $description
	): void {
		$raid = new Raid(
			started_by: $context->char->name,
			description: $description,
			announce_interval: $this->raidAnnouncement ? $this->raidAnnouncementInterval : 0,
			seconds_per_point: ($this->raidPointsForTime) ? $this->raidPointsInterval : 0,
			ticker_paused: $this->raidTickerStartPaused,
		);
		$this->startNewRaid($context, $raid);
	}

	/** Stop the currently running raid */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidStopCommand(
		CmdContext $context,
		#[NCA\Str('stop', 'end')] string $action
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$this->stopRaid($context->char->name);
	}

	/** Change the raid's description */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidChangeDescCommand(
		CmdContext $context,
		#[NCA\Regexp('description|descr?', example: 'description')] string $action,
		string $description
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$this->raid->description = $description;
		$this->logRaidChanges($this->raid);
		$context->reply('Raid description changed.');
		$event = new RaidChangeEvent(
			raid: $this->raid,
			player: $context->char->name,
		);
		$this->eventManager->fireEvent($event);
	}

	/** Change the raid's maximum number of members */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidChangeMaxMembersCommand(
		CmdContext $context,
		#[NCA\Str('limit')] string $action,
		#[NCA\PNumber] #[NCA\Str('off')] string $maxMembers
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$noLimit = in_array(strtolower($maxMembers), ['0', 'off'], true);
		$this->raid->max_members = $noLimit ? null : (int)$maxMembers;
		$this->logRaidChanges($this->raid);
		if ($noLimit) {
			$context->reply('Raid member limit removed.');
		} else {
			$context->reply("Maximum raid members set to <highlight>{$maxMembers}<end>.");
		}
		$event = new RaidChangeEvent(
			raid: $this->raid,
			player: $context->char->name,
		);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Change the interval for getting a participation raid point,
	 * 'off' to switch to manual rewarding,
	 * 'pause' to pause and 'resume' to resume the ticker
	 */
	#[NCA\HandlesCommand(self::CMD_RAID_TICKER)]
	public function raidChangeSppCommand(
		CmdContext $context,
		#[NCA\Str('ticker', 'spp')] string $action,
		#[NCA\PDuration] #[NCA\StrChoice('off', 'pause', 'resume')] string $interval
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		if ($interval === 'off') {
			$this->raid->seconds_per_point = 0;
			$context->reply('Raid ticker turned off. Points are now given via rewards by the raid leader(s).');
		} elseif ($interval === 'pause') {
			$this->raid->ticker_paused = true;
			$context->reply('Raid ticker paused.');
		} elseif ($interval === 'resume') {
			$this->raid->ticker_paused = false;
			$context->reply('Raid ticker resumed.');
		} else {
			$spp = Util::parseTime($interval);
			if ($spp === 0) {
				$context->reply("Invalid interval: {$interval}.");
				return;
			}
			$this->raid->seconds_per_point = $spp;
			$context->reply('Raid seconds per point changed.');
		}
		$this->logRaidChanges($this->raid);
		$event = new RaidChangeEvent(
			raid: $this->raid,
			player: $context->char->name,
		);
		$this->eventManager->fireEvent($event);
	}

	/** Change the raid announcement interval. 'off' to turn it off completely */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidChangeAnnounceCommand(
		CmdContext $context,
		#[NCA\Str('announce', 'announcement')] string $action,
		#[NCA\PDuration] #[NCA\Str('off')] string $interval
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		if (strtolower($interval) === 'off') {
			$this->raid->announce_interval = 0;
			$context->reply('Raid announcement turned off.');
		} else {
			$newInterval = Util::parseTime($interval);
			if ($newInterval === 0) {
				$context->reply("<highlight>{$interval}<end> is not a valid interval.");
				return;
			}
			$this->raid->announce_interval = $newInterval;
			$context->reply("Raid announcement interval changed to <highlight>{$interval}<end>.");
		}

		$this->logRaidChanges($this->raid);
		$event = new RaidChangeEvent(
			raid: $this->raid,
			player: $context->char->name,
		);
		$this->eventManager->fireEvent($event);
	}

	/** Lock the raid, preventing raiders from joining with <symbol>raid join */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidLockCommand(
		CmdContext $context,
		#[NCA\Str('lock')] string $action
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		if ($this->raid->locked) {
			$context->reply('The raid is already locked.');
			return;
		}
		$this->raid->locked = true;
		$this->logRaidChanges($this->raid);
		$lockMessage = "{$context->char->name} <off>locked<end> the raid.";
		if ($this->raidPointsController->raidTickerRequiresLock) {
			if ($this->raid->ticker_paused) {
				$lockMessage .= ' Raid point ticker is still <highlight>paused<end>.';
			} else {
				$lockMessage .= ' Raid point ticker is now <highlight>running<end>.';
			}
		}
		$this->routeMessage('lock', $lockMessage);
		$event = new RaidLockEvent(
			raid: $this->raid,
			player: $context->char->name,
		);
		$this->eventManager->fireEvent($event);
		$notInKick = $this->raidKickNotinOnLock;
		if ($notInKick !== 0) {
			$this->raidMemberController->kickNotInRaid($this->raid, $notInKick === 2);
		}
	}

	/** Unlock the raid, allowing raiders to join with <symbol>raid join */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidUnlockCommand(
		CmdContext $context,
		#[NCA\Str('unlock')] string $action
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		if ($this->raid->locked === false) {
			$context->reply('The raid is already unlocked.');
			return;
		}
		$this->raid->locked = false;
		$this->logRaidChanges($this->raid);
		$unlockMessage = "{$context->char->name} <on>unlocked<end> the raid.";
		if ($this->raidPointsController->raidTickerRequiresLock) {
			$unlockMessage .= ' Raid point ticker is <highlight>not running while unlocked<end>.';
		}
		$this->routeMessage('unlock', $unlockMessage);
		$event = new RaidUnlockEvent(
			raid: $this->raid,
			player: $context->char->name,
		);
		$this->eventManager->fireEvent($event);
	}

	/** Get a list of all raiders, with a link to check if everyone is in the vicinity */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidCheckCommand(
		CmdContext $context,
		#[NCA\Str('check')] string $action
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$msg = $this->raidMemberController->getRaidCheckBlob($this->raid);
		$context->reply($msg);
	}

	/** Get a list of all raiders */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidListCommand(
		CmdContext $context,
		#[NCA\Str('list')] string $action
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$context->reply($this->raidMemberController->getRaidListBlob($this->raid));
	}

	/**
	 * Kick everyone in the private channel who's not in the raid.
	 * If the additional 'all' is given, it will also kick raiders' alts not in the raid.
	 */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidNotinKickCommand(
		CmdContext $context,
		#[NCA\Str('notinkick')] string $action,
		#[NCA\Str('all')] ?string $all
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$notInRaid = $this->raidMemberController->kickNotInRaid($this->raid, isset($all));
		$numKicked = count($notInRaid);
		if ($numKicked === 0) {
			$context->reply('Everyone is in the raid.');
			return;
		}
		$context->reply(
			"<highlight>{$numKicked} " . Text::pluralize('player', $numKicked).
			'<end> kicked, because they are not in the raid.'
		);
	}

	/** Send everyone in the private channel who's not in the raid a reminder to join */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidNotinCommand(CmdContext $context, #[NCA\Str('notin')] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$notInRaid = $this->raidMemberController->sendNotInRaidWarning($this->raid);
		if (!count($notInRaid)) {
			$context->reply('Everyone is in the raid.');
			return;
		}
		$notInPlayers = Pipeline::fromIterable($notInRaid)
			->concurrent(4)
			->map($this->playerManager->byName(...))
			->toArray();
		$msg = $this->reportNotInResult($notInPlayers);
		$context->reply($msg);
	}

	/** Show a list of old raids with details about them */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidHistoryCommand(
		CmdContext $context,
		#[NCA\Str('history')] string $action
	): void {
		$query = $this->db->table(Raid::getTable(), 'r')
			->join(RaidPointsLog::getTable() . ' AS p', 'r.raid_id', 'p.raid_id')
			->where('p.individual', false)
			->orWhere('p.ticker', true)
			->groupBy('r.raid_id', 'r.started', 'r.stopped')
			->orderByDesc('r.raid_id')
			->limit(50)
			->select(['r.raid_id', 'r.started', 'r.stopped']);

		$raids = $query->addSelect([
			$query->raw(
				$query->rawFunc(
					'COUNT',
					$query->colFunc('DISTINCT', 'username'),
					'raiders'
				)
			),
			$query->raw($query->colFunc('SUM', 'delta', 'points')),
		])->asObj(RaidHistoryEntry::class);
		if ($raids->isEmpty()) {
			$msg = 'No raids have ever been run on <myname>.';
			$context->reply($msg);
			return;
		}
		$blob = '';
		foreach ($raids as $raid) {
			$time = (new DateTimeImmutable())->setTimestamp($raid->started)->format('Y-m-d H:i:s');
			$avgPoints = round($raid->points / $raid->raiders, 1);
			$detailsCmd = Text::makeChatcmd(
				'details',
				"/tell <myname> raid history {$raid->raid_id}"
			);
			$blob .= "<highlight>{$time}<end> - {$raid->raiders} raider ".
				"with avg. <highlight>{$avgPoints}<end> points ".
				"[{$detailsCmd}]\n";
		}
		$msg = $this->text->makeBlob('Last Raids (' . count($raids).')', $blob);
		$context->reply($msg);
	}

	/** Get detailed information about an old raid */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidHistoryDetailCommand(
		CmdContext $context,
		#[NCA\Str('history')] string $action,
		PUuid $raidId,
	): void {
		${$raidId} = $raidId();

		/** @var ?Raid */
		$raid = $this->db->table(Raid::getTable())
			->where('raid_id', $raidId)
			->asObj(Raid::class)->first();
		if ($raid === null) {
			$context->reply("The raid <highlight>{$raidId}<end> doesn't exist.");
			return;
		}
		$query = $this->db->table(RaidPointsLog::getTable())
			->where('raid_id', $raidId)
			->where('individual', false)
			->groupBy('username')
			->select('username');
		$query->addSelect($query->raw($query->colFunc('SUM', 'delta', 'delta')));

		$noPoints = $this->db->table(RaidMember::getTable(), 'rm')
			->leftJoin(RaidPointsLog::getTable() . ' as l', static function (JoinClause $join): void {
				$join->on('rm.raid_id', 'l.raid_id')
					->on('rm.player', 'l.username');
			})
			->where('rm.raid_id', $raidId)
			->whereNull('l.username')
			->groupBy('rm.player')
			->select('rm.player AS username');
		$noPoints->selectRaw('0' . $noPoints->as('delta'));

		$raiders = $this->db->fromSub($query->union($noPoints), 'points')
			->orderBy('username')
			->asObj(RaidPointsLog::class);

		$blob = $this->getRaidSummary($raid);
		$blob .= "\n<header2>Raiders and points<end>\n";
		foreach ($raiders as $raider) {
			$detailsCmd = Text::makeChatcmd(
				$raider->username,
				"/tell <myname> raid history {$raid->raid_id} {$raider->username}"
			);
			$main = $this->altsController->getMainOf($raider->username);
			$blob .= Text::alignNumber($raider->delta, 7).
				" - {$detailsCmd}";
			if ($raider->username !== $main) {
				$blob .= " (<highlight>{$main}<end>)";
			}
			$blob .= "\n";
		}
		$msg = $this->text->makeBlob("Raid {$raid->raid_id} details", $blob);
		$context->reply($msg);
	}

	/** Get detailed information about raid member of an old raid */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidHistoryDetailRaiderCommand(
		CmdContext $context,
		#[NCA\Str('history')] string $action,
		PUuid $raidId,
		PCharacter $char
	): void {
		$raidId = $raidId();

		/** @var ?Raid */
		$raid = $this->db->table(Raid::getTable())
			->where('raid_id', $raidId)
			->asObj(Raid::class)
			->first();
		if ($raid === null) {
			$context->reply("The raid <highlight>{$raidId}<end> doesn't exist.");
			return;
		}

		$logs = $this->db->table(RaidPointsLog::getTable())
			->where('raid_id', $raidId)
			->where('username', $char())
			->asObj(RaidPointsLog::class);
		$joined = $this->db->table(RaidMember::getTable())
			->where('raid_id', $raidId)
			->where('player', $char())
			->whereNotNull('joined')
			->select('joined AS time');
		$joined->selectRaw('1' . $joined->as('status'));
		$left = $this->db->table(RaidMember::getTable())
			->where('raid_id', $raidId)
			->where('player', $char())
			->whereNotNull('left')
			->select('left AS time');
		$left->selectRaw('0' . $left->as('status'));
		$events = $joined->union($left)->orderBy('time')->asObj(RaidStatus::class);

		/** @var Collection<int,RaidStatus|RaidPointsLog> */
		$allLogs = $logs->concat($events)
			->sort(static function (RaidStatus|RaidPointsLog $a, RaidStatus|RaidPointsLog $b): int {
				return $a->time <=> $b->time;
			});
		if ($allLogs->isEmpty()) {
			$context->reply("<highlight>{$char}<end> didn't get any points in this raid.");
			return;
		}
		$main = $this->altsController->getMainOf($char());
		$blob = $this->getRaidSummary($raid);
		$blob .= "\n<header2>Detailed points for {$char}";
		if ($main !== $char()) {
			$blob .= " ({$main})";
		}
		$blob .= "<end>\n";
		$blob .= '<tab>' . Util::date($raid->started) . '<tab>'.
			"Raid started by {$raid->started_by}\n";
		foreach ($allLogs as $log) {
			if ($log instanceof RaidPointsLog) {
				$blob .= '<tab>' . Util::date($log->time) . '<tab>'.
					Text::alignNumber(abs($log->delta), 5, $log->delta > 0 ? 'green' : 'red').
					' - '.
					($log->individual ? '<highlight>' : '').
					$log->reason.
					($log->individual ? '<end>' : '').
					" (by {$log->changed_by})\n";
			} elseif ($log instanceof RaidStatus) {
				$blob .= '<tab>' . Util::date($log->time) . '<tab>'.
					($log->status ? 'Raid joined' : 'Raid left') . "\n";
			}
		}
		if (isset($raid->stopped)) {
			$blob .= '<tab>' . Util::date($raid->stopped) . '<tab>'.
				"Raid stopped by {$raid->stopped_by}\n";
		}
		$msg = $this->text->makeBlob("Raid {$raid->raid_id} details for {$char}", $blob);
		$context->reply($msg);
	}

	/** Check if anyone in the current raid is dual-logged */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidDualCommand(
		CmdContext $context,
		#[NCA\Str('dual')] string $action
	): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}

		/** @var array<string,bool> */
		$mains = [];

		/** @var array<string,array<string,bool>> */
		$duals = [];
		foreach ($this->raid->raiders as $name => $raider) {
			if ($raider->left !== null) {
				continue;
			}
			$altInfo = $this->altsController->getAltInfo($raider->player);
			if (isset($mains[$altInfo->main])) {
				continue;
			}
			$mains[$altInfo->main] = true;
			foreach ($altInfo->getAllValidatedAlts() as $alt) {
				if ($alt === $name) {
					continue;
				}
				if (!isset($this->chatBot->chatlist[$alt])) {
					continue;
				}
				$duals[$name] ??= [];
				$duals[$name][$alt] = isset($this->raid->raiders[$alt])
					&& !isset($this->raid->raiders[$alt]->left);
			}
		}
		if (!count($duals)) {
			$context->reply('No one is currently dual-logged.');
			return;
		}
		$toLookup = [];
		foreach ($duals as $name => $alts) {
			$toLookup = [...$toLookup, $name, ...array_keys($alts)];
		}
		$tasks = [];
		foreach ($toLookup as $toLookupName) {
			$tasks[$toLookupName] = async($this->playerManager->byName(...), $toLookupName);
		}
		$lookup = await($tasks);
		$blob = '';
		foreach ($duals as $name => $alts) {
			$player = $lookup[$name];
			if ($player === null) {
				continue;
			}
			$blob .="<header2>{$name}<end>\n";
			$blob .= "<tab>- <highlight>{$name}<end> - {$player->level}/<green>{$player->ai_level}<end> {$player->profession?->value} :: <red>in raid<end>\n";
			foreach ($alts as $alt => $inRaid) {
				$player = $lookup[$alt];
				if ($player === null) {
					continue;
				}
				$blob .= "<tab>- <highlight>{$alt}<end> - {$player->level}/<green>{$player->ai_level}<end> {$player->profession?->value}";
				if ($inRaid) {
					$blob .= ' :: <red>in raid<end>';
				}
				$blob .= "\n";
			}
			$blob .= "\n";
		}
		$msg = $this->text->makeBlob(
			'Dual-logged players (' . count($duals) .')',
			$blob,
			'Dual-logged players with at last 1 char in the raid'
		);
		$context->reply($msg);
	}

	/** Log to the database whenever something of the raid changes */
	public function logRaidChanges(Raid $raid): void {
		if (!isset($raid->raid_id)) {
			return;
		}
		$this->db->insert(new RaidLog(
			raid_id: $raid->raid_id,
			description: $raid->description,
			seconds_per_point: $raid->seconds_per_point,
			ticker_paused: $raid->ticker_paused,
			locked: $raid->locked,
			time: time(),
			announce_interval: $raid->announce_interval,
			max_members: $raid->max_members,
		));
	}

	#[NCA\Event(
		name: SendPrivEvent::EVENT_MASK,
		description: 'Track when the bot sends messages on priv'
	)]
	public function trackOurPrivChannelMessages(SendPrivEvent $event): void {
		if (!isset($this->raid)) {
			return;
		}
		$this->raid->we_are_most_recent_message = false;
	}

	#[NCA\Event(
		name: MyPrivateChannelMsgEvent::EVENT_MASK,
		description: 'Track when someone sends messages on priv'
	)]
	public function trackPrivChannelMessages(MyPrivateChannelMsgEvent $event): void {
		if (!isset($this->raid) || $event->channel !== $this->config->main->character) {
			return;
		}
		$this->raid->we_are_most_recent_message = false;
	}

	#[NCA\Event(
		name: 'timer(30s)',
		description: 'Announce the running raid'
	)]
	public function announceRaidRunning(): void {
		if (!isset($this->raid) || $this->raid->announce_interval === 0) {
			return;
		}
		if (time() - $this->raid->last_announcement < $this->raid->announce_interval) {
			return;
		}
		if ($this->raid->we_are_most_recent_message) {
			return;
		}
		$this->routeMessage(
			'announce',
			$this->raid->getAnnounceMessage(
				((array)$this->text->makeBlob(
					'click to join',
					$this->getRaidJoinLink(),
					'Raid information'
				))[0]
			)
		);
		$this->raid->last_announcement = time();
		$this->raid->we_are_most_recent_message = true;
	}

	/** Announce when a raid was started */
	#[NCA\Event(
		name: RaidStartEvent::EVENT_MASK,
		description: 'Announce when a raid was started'
	)]
	public function announceRaidStart(RaidStartEvent $event): void {
		$this->routeMessage(
			'start',
			"<highlight>{$event->raid->started_by}<end> started a raid: ".
			"<highlight>{$event->raid->description}<end> :: ".
			((array)$this->text->makeBlob(
				'click to join',
				$this->getRaidJoinLink(),
				'Raid information'
			))[0]
		);
	}

	/** Announce when a raid was stopped. */
	#[NCA\Event(
		name: RaidStopEvent::EVENT_MASK,
		description: 'Announce when a raid is stopped'
	)]
	public function announceRaidStop(RaidStopEvent $event): void {
		$this->routeMessage('stop', "<highlight>{$event->player}<end> has stopped the raid.");
	}

	/** Start a new raid and also register it in the database */
	public function startRaid(Raid $raid): void {
		$oldRaid = $this->db->table(Raid::getTable())
			->where('raid_id', $raid->raid_id)
			->first();
		if (isset($oldRaid)) {
			$this->raid = $raid;
			return;
		}
		$this->db->insert($raid);
		$this->raid = $raid;
		$event = new RaidStartEvent(
			raid: $raid,
			player: $raid->started_by,
		);
		$this->eventManager->fireEvent($event);
		$this->logRaidChanges($this->raid);
	}

	/** Stop the current raid */
	public function stopRaid(string $sender): void {
		if (!isset($this->raid)) {
			return;
		}
		$raid = $this->raid;
		$raid->stopped = time();
		$raid->stopped_by = $sender;
		$this->db->table(Raid::getTable())
			->where('raid_id', $raid->raid_id)
			->update([
				'stopped' => $raid->stopped,
				'stopped_by' => $raid->stopped_by,
			]);
		$this->raid = null;
		if ($this->raidStopClearsCallers) {
			$this->chatAssistController->clearCallers($sender, 'raid stop');
		}
		$event = new RaidStopEvent(
			raid: $raid,
			player: ucfirst(strtolower($sender)),
		);
		$this->eventManager->fireEvent($event);
	}

	/** Show the notes about all people in the current raid */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidCommentsCommand(
		CmdContext $context,
		#[NCA\Regexp('notes?|comments?', example: 'notes')] string $action
	): void {
		if (!$context->isDM()) {
			$context->reply("<red>The '<symbol>raid {$action}' command only works in tells<end>.");
			return;
		}
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$raiderNames = array_keys($this->raid->raiders);
		$category = $this->getRaidCategory();
		$comments = $this->commentController->getComments($category, ...$raiderNames);
		$comments = $this->commentController->filterInaccessibleComments($comments, $context->char->name);
		if (!count($comments)) {
			$context->reply('There are no notes about any raider that you have access to.');
			return;
		}
		$format = $this->commentController->formatComments($comments, true);
		$msg = "Comments ({$format->numComments}) about the current raiders ({$format->numMains})";
		$msg = $this->text->makeBlob($msg, $format->blob);
		$context->reply($msg);
	}

	/** Add a new raid note about a character */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidCommentAddCommand(
		CmdContext $context,
		#[NCA\Regexp('notes?|comments?', example: 'note')] string $action,
		#[NCA\Str('add', 'create', 'new')] string $subAction,
		PCharacter $char,
		string $note
	): void {
		/** @psalm-var non-empty-string */
		$catName = $this->getRaidCategory()->name;
		$this->commentController->addCommentCommand(
			$context,
			'new',
			$char,
			new PWord($catName),
			$note
		);
	}

	/** Get all raid notes about a character */
	#[NCA\HandlesCommand(self::CMD_RAID_MANAGE)]
	public function raidCommentSearchCommand(
		CmdContext $context,
		#[NCA\Regexp('notes?|comments?', example: 'notes')] string $action,
		#[NCA\Str('get', 'read', 'search', 'find')] string $subAction,
		PCharacter $char
	): void {
		/** @psalm-var non-empty-string */
		$catName = $this->getRaidCategory()->name;
		$this->commentController->searchCommentCommand(
			$context,
			'get',
			$char,
			new PWord($catName),
		);
	}

	#[NCA\Event(
		name: 'timer(24h)',
		description: 'Remove non-raiding members from bot'
	)]
	public function removeNonRaidingMembers(): void {
		if ($this->raidDemoteMembersInterval === 0) {
			return;
		}
		$this->logger->notice('Removing non-raiding bot members');
		// Get a list of the main chars of everyone who's raided in the given timeframe
		$activeMains = $this->db->table(RaidMember::getTable())
			->where('joined', '>', time() - $this->raidDemoteMembersInterval)
			->select('player')
			->distinct()
			->pluckStrings('player')
			->map(function (string $player): string {
				return $this->altsController->getMainOf($player);
			})
			->unique()
			->mapToDictionary(static function (string $main): array {
				return [$main => true];
			})->toArray();
		$members = $this->privateChannelController->getMembers();
		// Remove all members who are older than the given timeframe and haven't raided
		// with un in the given timeframe
		foreach ($members as $member => $data) {
			$this->logger->info('Checking {name} for raid activity', ['name' => $member]);
			$memberMain = $this->altsController->getMainOf($member);
			if (isset($activeMains[$memberMain])) {
				$this->logger->info('{name} is active', ['name' => $member]);
				continue;
			}
			$mainData = $members[$memberMain] ?? $data;
			if (time() - $mainData->joined < $this->raidDemoteMembersInterval) {
				$this->logger->info('{name} is yet too young', ['name' => $mainData->name]);
				continue;
			}
			$result = strip_tags($this->privateChannelController->removeUser($member, $this->config->main->character));
			$this->logger->notice('Removing {member}: {result}', [
				'member' => $member,
				'result' => $result,
			]);
		}
		$this->logger->notice('Done removing non-raiding bot members');
	}

	protected function routeMessage(string $type, string $message): void {
		$rMessage = new RoutableMessage($message);
		$rMessage->prependPath(new Source('raid', $type));
		$this->messageHub->handle($rMessage);
	}

	protected function startNewRaid(CmdContext $context, Raid $raid): void {
		if (isset($this->raid)) {
			$context->reply("There's already a raid running.");
			return;
		}
		$this->startRaid($raid);
		if ($this->raidAutoAddCreator) {
			$this->raidMemberController->joinRaid($context->char->name, $context->char->name, $context->source, false);
		}
		$this->chatBot->sendTell(
			$this->text->makeBlob('Raid Control', $this->getControlInterface()),
			$context->char->name
		);
	}

	/**
	 * @param array<null|Player> $players
	 *
	 * @return list<string>
	 */
	protected function reportNotInResult(array $players): array {
		$blob = "<header2>Players that were warned<end>\n";
		ksort($players);
		$charNames = [];
		foreach ($players as $name => $player) {
			if ($player instanceof Player && isset($player->profession)) {
				$addLink = Text::makeChatcmd('Add to raid', "/tell <myname> <symbol>raid add {$player->name}");
				$profIcon = $player->profession->toIcon();
				$blob .= "<tab>{$profIcon} {$player->name} - {$player->level}/{$player->ai_level} [{$addLink}]\n";
				$charNames []= $player->name;
			} else {
				$addLink = Text::makeChatcmd('Add to raid', "/tell <myname> <symbol>raid add {$name}");
				$blob .= "<tab>{$name} [{$addLink}]\n";
				$charNames []= $name;
			}
		}
		$addAllLink = Text::makeChatcmd(
			'Add all to the raid',
			'/tell <myname> <symbol>raid add ' . implode(' ', $charNames)
		);
		$blob .= "\n{$addAllLink}";
		$s = (count($players) === 1) ? '' : 's';
		$msgs = (array)$this->text->makeBlob(count($players) . " player{$s}", $blob, 'Players not in the raid');
		foreach ($msgs as &$msg) {
			$msg = "Sent not in raid warning to {$msg}.";
		}
		return $msgs;
	}

	protected function getRaidSummary(Raid $raid): string {
		$blob  = "<header2>Raid Nr. {$raid->raid_id}<end>\n";
		$blob .= '<tab>Started:  '.
			'<highlight>' . Util::date($raid->started) . '<end> '.
			"by <highlight>{$raid->started_by}<end>\n";
		if (isset($raid->stopped)) {
			$blob .= '<tab>Stopped: '.
				'<highlight>' . Util::date($raid->stopped) . '<end> '.
				"by <highlight>{$raid->stopped_by}<end>\n";
		}
		$blob .= "<tab>Description: <highlight>{$raid->description}<end>\n";
		if (isset($raid->max_members) && $raid->max_members > 0) {
			$blob .= "<tab>Max members: <highlight>{$raid->max_members}<end>\n";
		}
		$blob .= '<tab>Raid points: ';
		if ($raid->seconds_per_point === 0) {
			$blob .= "<highlight>Given per kill<end>\n";
		} else {
			$blob .= '<highlight>1 point every '.
				Util::unixtimeToReadable($raid->seconds_per_point).
				'<end>';
			if ($raid->ticker_paused) {
				$blob .= ' (<red>paused<end>)';
			} else {
				$blob .= ' (<green>ticking<end>)';
			}
			if ($this->raidPointsController->raidTickerRequiresLock && !$raid->locked) {
				$blob .= ' (<red>not locked<end>)';
			}
			$blob .= "\n";
		}
		return $blob;
	}
}
