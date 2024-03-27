<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use function Safe\preg_match;
use Amp\File\{FilesystemException};
use AO\Package;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\Event\ConnectEvent;
use Nadybot\Core\Routing\Events\Base;
use Nadybot\Core\{
	AccessLevelProvider,
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	Config\BotConfig,
	DB,
	DBSchema\Audit,
	DBSchema\LastOnline,
	DBSchema\Member,
	DBSchema\Player,
	EventManager,
	Event\JoinMyPrivEvent,
	Event\LeaveMyPrivEvent,
	Filesystem,
	LogonEvent,
	MessageHub,
	ModuleInstance,
	Modules\ALTS\AltInfo,
	Modules\ALTS\AltsController,
	Modules\BAN\BanController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Registry,
	Routing\Character,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	SettingManager,
	SyncEvent,
	Text,
	Util,
};
use Nadybot\Modules\RAID_MODULE\RaidController;
use Nadybot\Modules\{
	GUILD_MODULE\GuildController,
	ONLINE_MODULE\OfflineEvent,
	ONLINE_MODULE\OnlineController,
	ONLINE_MODULE\OnlineEvent,
	ONLINE_MODULE\OnlinePlayer,
	RAID_MODULE\RaidRankController,
	WEBSERVER_MODULE\StatsController,
};
use Psr\Log\LoggerInterface;

use Revolt\EventLoop;
use stdClass;

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'members',
		accessLevel: 'member',
		description: 'Member list',
		alias: 'member',
	),
	NCA\DefineCommand(
		command: 'members inactive',
		accessLevel: 'guild',
		description: "List members who haven't logged in for some time",
	),
	NCA\DefineCommand(
		command: 'members add/remove',
		accessLevel: 'guild',
		description: 'Adds or removes a player to/from the members list',
	),
	NCA\DefineCommand(
		command: 'invite',
		accessLevel: 'guild',
		description: 'Invite players to the private channel',
		alias: 'inviteuser'
	),
	NCA\DefineCommand(
		command: 'kick',
		accessLevel: 'guild',
		description: 'Kick players from the private channel',
		alias: 'kickuser'
	),
	NCA\DefineCommand(
		command: 'autoinvite',
		accessLevel: 'member',
		description: 'Enable or disable autoinvite',
	),
	NCA\DefineCommand(
		command: 'count',
		accessLevel: 'guest',
		description: 'Shows how many characters are in the private channel',
	),
	NCA\DefineCommand(
		command: 'kickall',
		accessLevel: 'guild',
		description: 'Kicks all from the private channel',
	),
	NCA\DefineCommand(
		command: 'join',
		accessLevel: 'member',
		description: 'Join command for characters who want to join the private channel',
	),
	NCA\DefineCommand(
		command: 'leave',
		accessLevel: 'all',
		description: 'Leave command for characters in private channel',
	),
	NCA\DefineCommand(
		command: 'lock',
		accessLevel: 'superadmin',
		description: 'Kick everyone and lock the private channel',
	),
	NCA\DefineCommand(
		command: 'unlock',
		accessLevel: 'superadmin',
		description: 'Allow people to join the private channel again',
	),
	NCA\DefineCommand(
		command: 'lastonline',
		accessLevel: 'member',
		description: 'Shows the last logon-times of a character',
	),

	NCA\ProvidesEvent('online(priv)'),
	NCA\ProvidesEvent('offline(priv)'),
	NCA\ProvidesEvent(MemberAddEvent::class),
	NCA\ProvidesEvent(MemberRemoveEvent::class),
	NCA\EmitsMessages(Source::SYSTEM, 'lock-reminder')
]
class PrivateChannelController extends ModuleInstance implements AccessLevelProvider {
	public const DB_TABLE = 'members_<myname>';

	/** Automatically add player as member when they join */
	#[NCA\Setting\Boolean]
	public bool $addMemberOnJoin = false;

	/** Enable autoinvite for new members by default */
	#[NCA\Setting\Boolean]
	public bool $autoinviteDefault = true;

	/** Show profs with 0 people in '<symbol>count profs' */
	#[NCA\Setting\Boolean]
	public bool $countEmptyProfs = true;

	/** Faction allowed on the bot - autoban everything else */
	#[NCA\Setting\Options(options: [
		'all', 'Omni', 'Neutral', 'Clan', 'not Omni', 'not Neutral', 'not Clan',
	])]
	public string $onlyAllowFaction = 'all';

	/** Message when someone joins the private channel */
	#[NCA\Setting\Template(
		options: [
			'{whois} has joined {channel-name}. {alt-of}',
			'{whois} has joined {channel-name}. {alt-list}',
			'{c-name}{?main: ({main})}{?level: - {c-level}/{c-ai-level} {short-prof}} has joined.',
			'{c-name}{?nick: ({c-nick})}{!nick:{?main: ({main})}}{?level: - {c-level}/{c-ai-level} {short-prof}} has joined.',
			'<on>+<end> {c-name}{?main: ({main})}{?level: - {c-level}/{c-ai-level} {short-prof}}{?org: - {org-rank} of {c-org}}{?admin-level: :: {c-admin-level}}',
			'<on>+<end> {c-name}{?nick: ({c-nick})}{!nick:{?main: ({main})}}{?level: - {c-level}/{c-ai-level} {short-prof}}{?org: - {org-rank} of {c-org}}{?admin-level: :: {c-admin-level}}',
			'{name}{?level: :: {c-level}/{c-ai-level} {short-prof}}{?org: :: {c-org}} joined us{?admin-level: :: {c-admin-level}}{?main: :: {c-main}}',
			'{c-name}{?level: ({c-level}/{c-ai-level} {c-faction} {c-profession}{?org:, <highlight>{org}<end>})} has joined <myname>{?main: (alt of {main})}',
		],
		help: 'priv_join_message.txt'
	)]
	public string $privJoinMessage = '{whois} has joined {channel-name}. {alt-list}';

	/** Message when someone leaves the private channel */
	#[NCA\Setting\Template(
		options: [
			'{c-name} has left {channel-name}.',
			'{c-name}{?main: ({main})} has left {channel-name}.',
			'<off>-<end> {c-name}{?main: ({main})}{?level: - {c-level}/{c-ai-level} {short-prof}}{?org: - {org-rank} of {c-org}}{?admin-level: :: {c-admin-level}}',
		],
		help: 'priv_join_message.txt'
	)]
	public string $privLeaveMessage = '{c-name} has left {channel-name}.';

	/** Should the bot allow inviting banned characters? */
	#[NCA\Setting\Boolean]
	public bool $inviteBannedChars = false;

	/** Message to send when welcoming new members */
	#[NCA\Setting\Text(
		options: [
			'<link>Welcome to <myname></link>!',
			'Welcome to <myname>! Here is some <link>information to get you started</link>.',
		],
		help: 'welcome_msg.txt'
	)]
	public string $welcomeMsgString = '<link>Welcome to <myname></link>!';

	/** Minimum rank allowed to join private channel during a lock */
	#[NCA\Setting\Rank(accessLevel: 'superadmin')]
	public string $lockMinrank = 'superadmin';

	/** If set, the private channel is currently locked for a reason */
	#[NCA\Setting\Text(mode: 'noedit')]
	public string $lockReason = '';

	/** @var array<string,Member> */
	protected array $members = [];

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
	private Preferences $preferences;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private GuildController $guildController;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private OnlineController $onlineController;

	#[NCA\Inject]
	private StatsController $statsController;

	#[NCA\Inject]
	private RaidRankController $raidRankController;

	#[NCA\Inject]
	private RaidController $raidController;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);
		$this->commandAlias->register(
			$this->moduleName,
			'members add',
			'adduser'
		);
		$this->commandAlias->register(
			$this->moduleName,
			'members del',
			'deluser'
		);
		$this->commandAlias->register(
			$this->moduleName,
			'members del',
			'remuser'
		);
		$lockStats = new PrivLockStats();
		Registry::injectDependencies($lockStats);
		$this->statsController->registerProvider($lockStats, 'states');
		$this->cacheMembers();
	}

	public function cacheMembers(): void {
		$this->members = $this->db->table(self::DB_TABLE)
			->asObj(Member::class)
			->keyBy('name')
			->toArray();
	}

	/** @return array<string,Member> */
	public function getMembers(): array {
		return $this->members;
	}

	public function getSingleAccessLevel(string $sender): ?string {
		$isMember = isset($this->members[$sender]);
		if ($isMember) {
			return 'member';
		}
		if (isset($this->chatBot->chatlist[$sender])) {
			return 'guest';
		}
		return null;
	}

	#[NCA\SettingChangeHandler('welcome_msg_string')]
	public function validateWelcomeMsg(string $setting, string $old, string $new): void {
		if (preg_match('|&lt;link&gt;.+?&lt;/link&gt;|', $new)) {
			throw new Exception(
				'You have to use <highlight><symbol>htmldecode settings save ...<end> if your settings contain '.
				'tags like &lt;link&gt;, because the AO client escapes the tags. '.
				'This command is part of the DEV_MODULE.'
			);
		}
		if (!preg_match('|<link>.+?</link>|', $new)) {
			throw new Exception(
				'Your message must contain a block of <highlight>&lt;link&gt;&lt;/link&gt;<end> which will '.
				'then be a popup with the actual welcome message. The link text sits between '.
				'the tags, e.g. <highlight>&lt;link&gt;click me&lt;/link&gt;<end>.'
			);
		}
		if (substr_count($new, '<link>') > 1 || substr_count($new, '</link>') > 1) {
			throw new Exception(
				'Your message can only contain a single block of <highlight>&lt;link&gt;&lt;/link&gt;<end>.'
			);
		}
		if (substr_count($new, '<') !== substr_count($new, '>')) {
			throw new Exception('Your text seems to be invalid HTML.');
		}
		$stripped = strip_tags($new);
		if (preg_match('/[<>]/', $stripped)) {
			throw new Exception('Your text seems to generate invalid HTML.');
		}
	}

	/** Show who is a member of the bot */
	#[NCA\HandlesCommand('members')]
	#[NCA\Help\Group('private-channel')]
	public function membersCommand(CmdContext $context): void {
		/** @var Collection<Member> */
		$members = $this->db->table(self::DB_TABLE)
			->orderBy('name')
			->asObj(Member::class);
		$count = $members->count();
		if ($count === 0) {
			$context->reply('This bot has no members.');
			return;
		}
		$list = "<header2>Members of <myname><end>\n";
		foreach ($members as $member) {
			$online = $this->buddylistManager->isOnline($member->name);
			if (isset($this->chatBot->chatlist[$member->name])) {
				$status = '(<on>Online and in channel<end>)';
			} elseif ($online === true) {
				$status = '(<on>Online<end>)';
			} elseif ($online === false) {
				$status = '(<off>Offline<end>)';
			} else {
				$status = '(<orange>Unknown<end>)';
			}

			$list .= "<tab>{$member->name} {$status}\n";
		}

		$msg = $this->text->makeBlob("Members ({$count})", $list);
		$context->reply($msg);
	}

	/** Show members who have not logged on for a specified amount of time */
	#[NCA\HandlesCommand('members inactive')]
	#[NCA\Help\Group('private-channel')]
	public function inactiveMembersCommand(
		CmdContext $context,
		#[NCA\Str('inactive')] string $action,
		?PDuration $duration
	): void {
		$duration ??= new PDuration('1y');
		$time = $duration->toSecs();
		if ($time < 1) {
			$msg = 'You must enter a valid time parameter.';
			$context->reply($msg);
			return;
		}

		/** @var Collection<Member> */
		$members = $this->db->table(self::DB_TABLE)->asObj(Member::class);
		if ($members->isEmpty()) {
			$context->reply('This bot has no members.');
			return;
		}

		$timeString = $this->util->unixtimeToReadable($time, false);
		$time = time() - $time;

		/**
		 * Main char => information about the char last online of the main
		 *
		 * @var Collection<string,LastOnline>
		 */
		$lastOnline = $this->db->table('last_online')
			->orderBy('dt')
			->asObj(LastOnline::class)
			->each(function (LastOnline $member): void {
				$member->main = $this->altsController->getMainOf($member->name);
			})
			->keyBy('main')
			->filter(function (LastOnline $member, string $main): bool {
				return $this->accessManager->checkSingleAccess($main, 'member');
			});

		/** @var Collection<InactiveMember> */
		$inactiveMembers = $members->keyBy(function (Member $member): string {
			return $this->altsController->getMainOf($member->name);
		})->map(static function (Member $member, string $main) use ($lastOnline): InactiveMember {
			$result = new InactiveMember();
			$result->name = $member->name;
			$result->last_online = $lastOnline->get($main, null);
			return $result;
		})->filter(static function (InactiveMember $member) use ($time): bool {
			return $member->last_online?->dt < $time;
		})->sortKeys()
		->values();

		if ($inactiveMembers->isEmpty()) {
			$context->reply('There are no inactive members in the database.');
			return;
		}

		$blob = 'Members who have not logged on for '.
			"<highlight>{$timeString}<end>.\n\n".
			"<header2>Inactive bot members<end>\n";

		$lines = [];
		foreach ($inactiveMembers as $inactiveMember) {
			$remLink = $this->text->makeChatcmd(
				'kick',
				"/tell <myname> member remall {$inactiveMember->name}",
			);
			$line = "<pagebreak><tab>{$inactiveMember->name} - ".
				'last seen: ';
			if (isset($inactiveMember->last_online)) {
				$line .= '<highlight>'.
					$this->util->date($inactiveMember->last_online->dt).
					"<end> on {$inactiveMember->last_online->name}";
			} else {
				$line .= '<highlight>never<end>';
			}
			$lines []= $line . " [{$remLink}]";
		}
		$msg = $this->text->makeBlob(
			'Inactive Org Members (' . $inactiveMembers->count() . ')',
			$blob . implode("\n", $lines)
		);
		$context->reply($msg);
	}

	/**
	 * Make someone a member of this bot
	 * They will get auto-invited after logging in
	 */
	#[NCA\HandlesCommand('members add/remove')]
	#[NCA\Help\Group('private-channel')]
	public function addUserCommand(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		PCharacter $char
	): void {
		try {
			$msg = $this->addUser($char(), $context->char->name);
		} catch (Exception $e) {
			$msg = $e->getMessage();
		}

		$context->reply($msg);
	}

	/** Remove someone from the bot's member list */
	#[NCA\HandlesCommand('members add/remove')]
	#[NCA\Help\Group('private-channel')]
	public function remUserCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $member
	): void {
		$msg = $this->removeUser($member(), $context->char->name);

		$context->reply($msg);
	}

	/** Remove someone and all their alts from the bot's member list */
	#[NCA\HandlesCommand('members add/remove')]
	#[NCA\Help\Group('private-channel')]
	public function remallUserCommand(
		CmdContext $context,
		#[NCA\Str('remall', 'delall')] string $action,
		PCharacter $member
	): void {
		$main = $this->altsController->getMainOf($member());
		$alts = $this->altsController->getAltsOf($main);
		$lines = [];
		foreach ($alts as $alt) {
			$lines []= '<tab>- ' . $this->removeUser($alt, $context->char->name);
		}

		$context->reply(
			$this->text->makeBlob(
				"Removed alts of {$main} (" . count($alts) . ')',
				"<header2>Alts of {$main}<end>\n".
				implode("\n", $lines)
			)
		);
	}

	/**
	 * Invite someone to the bot's private channel. This won't make
	 * them a member, but they will have access level 'guest'
	 */
	#[NCA\HandlesCommand('invite')]
	#[NCA\Help\Group('private-channel')]
	public function inviteCommand(CmdContext $context, PCharacter $char): void {
		$name = $char();
		$uid = $this->chatBot->getUid($name);
		if (!isset($uid)) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		if ($this->config->main->character == $name) {
			$msg = 'You cannot invite the bot to its own private channel.';
			$context->reply($msg);
			return;
		}
		if (isset($this->chatBot->chatlist[$name])) {
			$msg = "<highlight>{$name}<end> is already in the private channel.";
			$context->reply($msg);
			return;
		}
		if ($this->isLockedFor($context->char->name)) {
			$context->reply("The private channel is currently <off>locked<end>: {$this->lockReason}");
			return;
		}
		if (!$this->inviteBannedChars && ($this->banController->isOnBanlist($uid))) {
			$msg = "<highlight>{$name}<end> is banned from <highlight><myname><end>.";
			$context->reply($msg);
			return;
		}
		$msg = "Invited <highlight>{$name}<end> to this channel.";
		$this->inviteChar($name);
		$audit = new Audit(
			actor: $context->char->name,
			actee: $name,
			action: AccessManager::INVITE,
		);
		$this->accessManager->addAudit($audit);
		$msg2 = "You have been invited to the <highlight><myname><end> channel by <highlight>{$context->char->name}<end>.";
		$this->chatBot->sendMassTell($msg2, $name);

		$context->reply($msg);
	}

	/**
	 * Kick someone off the bot's private channel. This won't remove
	 * their membership status (if any)
	 */
	#[NCA\HandlesCommand('kick')]
	#[NCA\Help\Group('private-channel')]
	public function kickCommand(CmdContext $context, PCharacter $char, ?string $reason): void {
		$name = $char();
		$uid = $this->chatBot->getUid($name);
		if (!isset($uid)) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
		} elseif (!isset($this->chatBot->chatlist[$name])) {
			$msg = "Character <highlight>{$name}<end> is not in the private channel.";
		} else {
			if ($this->accessManager->compareCharacterAccessLevels($context->char->name, $name) > 0) {
				$msg = "<highlight>{$name}<end> has been kicked from the private channel";
				if (isset($reason)) {
					$msg .= ": <highlight>{$reason}<end>";
				} else {
					$msg .= '.';
				}
				$this->chatBot->sendPrivate($msg);
				$this->kickChar($name);
				$audit = new Audit(
					actor: $context->char->name,
					actee: $name,
					action: AccessManager::KICK,
					value: $reason,
				);
				$this->accessManager->addAudit($audit);
			} else {
				$msg = "You do not have the required access level to kick <highlight>{$name}<end>.";
			}
		}
		if ($context->isDM()) {
			$context->reply($msg);
		}
	}

	/** Change your auto invite preference */
	#[NCA\HandlesCommand('autoinvite')]
	public function autoInviteCommand(CmdContext $context, bool $status): void {
		if ($status) {
			$onOrOff = 1;
			$this->buddylistManager->addName($context->char->name, 'member');
		} else {
			$onOrOff = 0;
		}

		if (!$this->db->table(self::DB_TABLE)->where('name', $context->char->name)->exists()) {
			$memberObj = new Member(
				name: $context->char->name,
				added_by: $context->char->name,
				autoinv: $onOrOff,
			);
			$this->db->insert(self::DB_TABLE, $memberObj);
			$this->members[$context->char->name] = $memberObj;
			$msg = 'You have been added as a member of this bot. '.
				'Use <highlight><symbol>autoinvite<end> to control '.
				'your auto invite preference.';
			$event = new MemberAddEvent(sender: $context->char->name);
			$this->eventManager->fireEvent($event);
		} else {
			$this->db->table(self::DB_TABLE)
				->where('name', $context->char->name)
				->update(['autoinv' => $onOrOff]);
			$msg = 'Your auto invite preference has been updated.';
		}

		$context->reply($msg);
	}

	/** Show how many people are in the private channel, grouped by level */
	#[NCA\HandlesCommand('count')]
	public function countLevelCommand(
		CmdContext $context,
		#[NCA\Str('raid')] ?string $raidOnly,
		#[NCA\Regexp('levels?|lvls?', example: 'lvl')] string $action
	): void {
		$tl1 = 0;
		$tl2 = 0;
		$tl3 = 0;
		$tl4 = 0;
		$tl5 = 0;
		$tl6 = 0;
		$tl7 = 0;

		$chars = $this->onlineController->getPlayers('priv', $this->config->main->character);
		if (isset($raidOnly)) {
			[$errMsg, $chars] = $this->filterRaid($chars);
			if (isset($errMsg)) {
				$context->reply($errMsg);
				return;
			}
		}
		$numonline = count($chars);
		foreach ($chars as $char) {
			if (!isset($char->level)) {
				continue;
			}
			if ($char->level > 1 && $char->level <= 14) {
				$tl1++;
			} elseif ($char->level <= 49) {
				$tl2++;
			} elseif ($char->level <= 99) {
				$tl3++;
			} elseif ($char->level <= 149) {
				$tl4++;
			} elseif ($char->level <= 189) {
				$tl5++;
			} elseif ($char->level <= 204) {
				$tl6++;
			} else {
				$tl7++;
			}
		}
		$msg = "<highlight>{$numonline}<end> in total: ".
			"TL1: <highlight>{$tl1}<end>, ".
			"TL2: <highlight>{$tl2}<end>, ".
			"TL3: <highlight>{$tl3}<end>, ".
			"TL4: <highlight>{$tl4}<end>, ".
			"TL5: <highlight>{$tl5}<end>, ".
			"TL6: <highlight>{$tl6}<end>, ".
			"TL7: <highlight>{$tl7}<end>";
		$context->reply($msg);
	}

	/** Show how many people are in the private channel, grouped by profession */
	#[NCA\HandlesCommand('count')]
	public function countProfessionCommand(
		CmdContext $context,
		#[NCA\Str('raid')] ?string $raidOnly,
		#[NCA\Regexp('all|profs?', example: 'profs')] string $action
	): void {
		$chars = $this->onlineController->getPlayers('priv', $this->config->main->character);
		if (isset($raidOnly)) {
			[$errMsg, $chars] = $this->filterRaid($chars);
			if (isset($errMsg)) {
				$context->reply($errMsg);
				return;
			}
		}
		$chars = new Collection($chars);
		$online = $chars->countBy('profession')->toArray();
		$numOnline = $chars->count();
		$profs = [
			'Adventurer', 'Agent', 'Bureaucrat', 'Doctor', 'Enforcer', 'Engineer',
			'Fixer', 'Keeper', 'Martial Artist', 'Meta-Physicist', 'Nano-Technician',
			'Soldier', 'Shade', 'Trader',
		];
		if (!$this->countEmptyProfs && !$numOnline) {
			$context->reply('<highlight>0<end> in total.');
			return;
		}
		$msg = "<highlight>{$numOnline}<end> in total: ";
		$parts = [];
		foreach ($profs as $prof) {
			$count = $online[$prof] ?? 0;
			if ($count === 0 && !$this->countEmptyProfs) {
				continue;
			}
			$short = $this->util->getProfessionAbbreviation($prof);
			$parts []= "<highlight>{$count}<end> {$short}";
		}
		$msg .= implode(', ', $parts);

		$context->reply($msg);
	}

	/** Show how many people are in the private channel, grouped by organization */
	#[NCA\HandlesCommand('count')]
	public function countOrganizationCommand(
		CmdContext $context,
		#[NCA\Str('raid')] ?string $raidOnly,
		#[NCA\Regexp('orgs?', example: 'orgs')] string $action
	): void {
		$online = $this->onlineController->getPlayers('priv', $this->config->main->character);
		if (isset($raidOnly)) {
			[$errMsg, $online] = $this->filterRaid($online);
			if (isset($errMsg)) {
				$context->reply($errMsg);
				return;
			}
		}
		$online = new Collection($online);

		if ($online->isEmpty()) {
			$msg = 'No characters in channel.';
			if (isset($raidOnly)) {
				$msg = 'No characters in the raid.';
			}
			$context->reply($msg);
			return;
		}
		$byOrg = $online->groupBy('guild');

		/** @var Collection<OrgCount> */
		$orgStats = $byOrg->map(static function (Collection $chars, string $orgName): OrgCount {
			$result = new OrgCount();
			$result->avgLevel = $chars->avg('level');
			$result->numPlayers = $chars->count();
			$result->orgName = strlen($orgName) ? $orgName : null;
			return $result;
		})->flatten()->sortByDesc('avgLevel')->sortByDesc('numPlayers');

		$lines = $orgStats->map(function (OrgCount $org) use ($online): string {
			$guild = $org->orgName ?? '(none)';
			$percent = $this->text->alignNumber(
				(int)round($org->numPlayers * 100 / $online->count(), 0),
				3
			);
			$avg_level = round($org->avgLevel, 1);
			return "<tab>{$percent}% <highlight>{$guild}<end> - {$org->numPlayers} member(s), average level {$avg_level}";
		});
		$blob = "<header2>Org statistics<end>\n" . $lines->join("\n");

		$msg = $this->text->makeBlob('Organizations (' . $lines->count() . ')', $blob);
		$context->reply($msg);
	}

	/** Show how many people are in the private channel of a given profession */
	#[NCA\HandlesCommand('count')]
	public function countCommand(
		CmdContext $context,
		#[NCA\Str('raid')] ?string $raidOnly,
		string $profession
	): void {
		$prof = $this->util->getProfessionName($profession);
		if ($prof === '') {
			$msg = 'Please choose one of these professions: adv, agent, crat, doc, enf, eng, fix, keep, ma, mp, nt, sol, shade, trader or all';
			$context->reply($msg);
			return;
		}

		/** @var Collection<OnlinePlayer> */
		$data = (new Collection($this->onlineController->getPlayers('priv', $this->config->main->character)))
			->where('profession', $prof);
		if (isset($raidOnly)) {
			[$errMsg, $data] = $this->filterRaid($data->toArray());
			if (isset($errMsg)) {
				$context->reply($errMsg);
				return;
			}
			$data = new Collection($data);
		}
		$numOnline = $data->count();
		if ($numOnline === 0) {
			$msg = "<highlight>{$numOnline}<end> {$prof}s.";
			$context->reply($msg);
			return;
		}
		$msg = "<highlight>{$numOnline}<end> {$prof}:";

		foreach ($data as $row) {
			if ($row->afk !== '') {
				$afk = ' <red>*AFK*<end>';
			} else {
				$afk = '';
			}
			$msg .= " [<highlight>{$row->name}<end> - {$row->level}{$afk}]";
		}
		$context->reply($msg);
	}

	/** Immediately kick everyone off the bot's private channel */
	#[NCA\HandlesCommand('kickall')]
	public function kickallNowCommand(CmdContext $context, #[NCA\Str('now')] string $action): void {
		$this->chatBot->sendPackage(
			package: new Package\Out\PrivateChannelKickAll()
		);
	}

	/** Kick everyone off the bot's private channel after 10 seconds */
	#[NCA\HandlesCommand('kickall')]
	public function kickallCommand(CmdContext $context): void {
		$msg = "Everyone will be kicked from this channel in 10 seconds. [by <highlight>{$context->char->name}<end>]";
		$this->chatBot->sendPrivate($msg);
		EventLoop::delay(10, function (string $token): void {
			$this->chatBot->sendPackage(
				package: new Package\Out\PrivateChannelKickAll()
			);
		});
	}

	/** Join this bot's private channel (if you have the permission) */
	#[NCA\HandlesCommand('join')]
	#[NCA\Help\Group('private-channel')]
	public function joinCommand(CmdContext $context): void {
		if ($this->isLockedFor($context->char->name)) {
			$context->reply("The private channel is currently <off>locked<end>: {$this->lockReason}");
			return;
		}
		if (isset($this->chatBot->chatlist[$context->char->name])) {
			$msg = 'You are already in the private channel.';
			$context->reply($msg);
			return;
		}
		$this->inviteChar($context->char->name);
		if (!$this->addMemberOnJoin) {
			return;
		}
		if ($this->db->table(self::DB_TABLE)->where('name', $context->char->name)->exists()) {
			return;
		}
		$autoInvite = $this->autoinviteDefault;
		$memberObj = new Member(
			name: $context->char->name,
			added_by: $context->char->name,
			autoinv: $autoInvite ? 1 : 0,
		);
		$this->db->insert(self::DB_TABLE, $memberObj);
		$this->members[$context->char->name] = $memberObj;
		$msg = 'You have been added as a member of this bot. '.
			'Use <highlight><symbol>autoinvite<end> to control your '.
			'auto invite preference.';
		$event = new MemberAddEvent(sender: $context->char->name);
		$this->eventManager->fireEvent($event);
		$context->reply($msg);
	}

	/** Leave this bot's private channel */
	#[NCA\HandlesCommand('leave')]
	#[NCA\Help\Group('private-channel')]
	public function leaveCommand(CmdContext $context): void {
		$this->kickChar($context->char->name);
	}

	/**
	 * Lock the private channel, forbidding anyone to join
	 *
	 * Locking the private channel is persistent across bot restarts
	 */
	#[NCA\HandlesCommand('lock')]
	#[NCA\Help\Group('lock')]
	public function lockCommand(CmdContext $context, string $reason): bool {
		$reason = trim($reason);
		if (!strlen($reason)) {
			return false;
		}
		if (strlen($this->lockReason)) {
			$this->settingManager->save('lock_reason', trim($reason));
			$context->reply('Lock reason changed.');
			return true;
		}
		$this->settingManager->save('lock_reason', trim($reason));
		$this->chatBot->sendPrivate("The private chat has been <off>locked<end> by {$context->char->name}: <highlight>{$this->lockReason}<end>");
		$alRequired = $this->lockMinrank;
		foreach ($this->chatBot->chatlist as $char => $online) {
			$alChar = $this->accessManager->getAccessLevelForCharacter($char);
			if ($this->accessManager->compareAccessLevels($alChar, $alRequired) < 0) {
				$this->kickChar($char);
			}
		}
		$context->reply("You <off>locked<end> the private channel: {$this->lockReason}");
		$audit = new Audit(
			actor: $context->char->name,
			action: AccessManager::LOCK,
			value: $this->lockReason,
		);
		$this->accessManager->addAudit($audit);
		return true;
	}

	/** Open the private channel again */
	#[NCA\HandlesCommand('unlock')]
	#[NCA\Help\Group('lock')]
	public function unlockCommand(CmdContext $context): void {
		if (!$this->isLocked()) {
			$context->reply('The private channel is currently not locked.');
			return;
		}
		$this->settingManager->save('lock_reason', '');
		$this->chatBot->sendPrivate('The private chat is now <on>open<end> again.');
		$context->reply('You <on>unlocked<end> the private channel.');
		$audit = new Audit(
			actor: $context->char->name,
			action: AccessManager::UNLOCK,
		);
		$this->accessManager->addAudit($audit);
	}

	#[NCA\Event(
		name: 'timer(5m)',
		description: 'Send reminder if the private channel is locked'
	)]
	public function remindOfLock(): void {
		if (!$this->isLocked()) {
			return;
		}
		$msg = 'Reminder: the private channel is currently <off>locked<end>!';
		$rMessage = new RoutableMessage($msg);
		$rMessage->prependPath(new Source(Source::SYSTEM, 'lock-reminder'));
		$this->messageHub->handle($rMessage);
	}

	#[NCA\Event(
		name: ConnectEvent::EVENT_MASK,
		description: 'Adds all members as buddies'
	)]
	public function connectEvent(ConnectEvent $eventObj): void {
		$this->db->table(self::DB_TABLE)
			->asObj(Member::class)
			->each(function (Member $member): void {
				$this->buddylistManager->addName($member->name, 'member');
			});
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Auto-invite members on logon'
	)]
	public function logonAutoinviteEvent(LogonEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}

		/** @var Member[] */
		$data = $this->db->table(self::DB_TABLE)
			->where('name', $sender)
			->where('autoinv', 1)
			->asObj(Member::class)
			->toArray();
		if (!count($data)) {
			return;
		}
		$uid = $this->chatBot->getUid($eventObj->sender);
		if ($uid === null) {
			return;
		}
		if ($this->isLockedFor($sender)) {
			return;
		}
		if ($this->banController->isOnBanlist($uid)) {
			return;
		}
		$channelName = 'the <highlight><myname><end> channel';
		if ($this->settingManager->getBool('guild_channel_status') === false) {
			$channelName = '<highlight><myname><end>';
		}
		$msg = "You have been auto invited to {$channelName}. ".
			'Use <highlight><symbol>autoinvite<end> to control '.
			'your auto invite preference.';
		$this->inviteChar($uid);
		$this->chatBot->sendMassTell($msg, $sender);
	}

	public function getLogonMessage(string $player): ?string {
		if (!$this->guildController->canShowLogonMessageForChar($player)) {
			return null;
		}
		$altInfo = $this->altsController->getAltInfo($player);
		$whois = $this->playerManager->byName($player);
		return $this->getLogonMessageForPlayer($whois, $player, $altInfo);
	}

	public function dispatchRoutableEvent(Base|SyncEvent|stdClass $event): void {
		$label = null;
		if (strlen($this->config->general->orgName)) {
			$label = 'Guest';
		}
		$re = new RoutableEvent(
			type: RoutableEvent::TYPE_EVENT,
			data: $event,
			path: [new Source(Source::PRIV, $this->config->main->character, $label)],
		);
		$this->messageHub->handle($re);
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Displays a message when a character joins the private channel'
	)]
	public function joinPrivateChannelMessageEvent(JoinMyPrivEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$sender = $eventObj->sender;

		$msg = $this->getLogonMessage($sender);
		if (!isset($msg)) {
			return;
		}
		$uid = $this->chatBot->getUid($sender);
		$e = new Online();
		$e->char = new Character($sender, $uid);
		$e->main = $this->altsController->getMainOf($sender);
		$e->online = true;
		$e->message = $msg;
		$this->dispatchRoutableEvent($e);
		$this->chatBot->sendPrivate($msg, true);
		$this->guildController->lastLogonMsgs[$e->main] = time();

		$whois = $this->playerManager->byName($sender);
		if (!isset($whois)) {
			return;
		}
		$player = OnlinePlayer::fromPlayer($whois);
		$player::fromPlayer($whois);
		$player->online = true;
		$altInfo = $this->altsController->getAltInfo($sender);
		$player->pmain = $altInfo->main;
		$event = new OnlineEvent(
			player: $player,
			channel: 'priv',
		);
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Autoban players of unwanted factions when they join the bot'
	)]
	public function autobanOnJoin(JoinMyPrivEvent $eventObj): void {
		$reqFaction = $this->onlyAllowFaction;
		if ($reqFaction === 'all' || !is_string($eventObj->sender)) {
			return;
		}
		$player = $this->playerManager->byName($eventObj->sender);
		$this->autobanUnwantedFactions($player);
	}

	/** Automatically ban players if they are not of the wanted faction */
	public function autobanUnwantedFactions(?Player $whois): void {
		if (!isset($whois)) {
			return;
		}
		$reqFaction = $this->onlyAllowFaction;
		if ($reqFaction === 'all') {
			return;
		}
		// check faction limit
		if (
			in_array($reqFaction, ['Omni', 'Clan', 'Neutral'])
			&& $reqFaction === $whois->faction
		) {
			return;
		}
		if (in_array($reqFaction, ['not Omni', 'not Clan', 'not Neutral'], true)) {
			$tmp = explode(' ', $reqFaction);
			if ($tmp[1] !== $whois->faction) {
				return;
			}
		}
		// Ban
		$faction = strtolower($whois->faction);
		$this->banController->add(
			$whois->charid,
			$this->config->main->character,
			null,
			sprintf(
				'Autoban, because %s %s %s',
				$whois->getPronoun(),
				$whois->getIsAre(),
				$faction
			)
		);
		$this->chatBot->sendPrivate(
			"<highlight>{$whois->name}<end> has been auto-banned. ".
			"Reason: <{$faction}>{$faction}<end>."
		);
		$this->kickChar($whois->name);
		$audit = new Audit(
			actor: $this->config->main->character,
			actee: $whois->name,
			action: AccessManager::KICK,
			value: 'auto-ban',
		);
		$this->accessManager->addAudit($audit);
	}

	public function getLogoffMessage(string $player): ?string {
		$whois = $this->playerManager->byName($player);
		$altInfo = $this->altsController->getAltInfo($player);
		if (!$this->guildController->canShowLogoffMessageForChar($player)) {
			return null;
		}

		$tokens = $this->getTokensForJoinLeave($player, $whois, $altInfo);
		$leaveMessage = $this->text->renderPlaceholders($this->privLeaveMessage, $tokens);
		$leaveMessage = Safe::pregReplace(
			'/&lt;([a-z]+)&gt;/',
			'<$1>',
			$leaveMessage
		);
		assert(is_string($leaveMessage));

		return $leaveMessage;
	}

	#[NCA\Event(
		name: LeaveMyPrivEvent::EVENT_MASK,
		description: 'Displays a message when a character leaves the private channel'
	)]
	public function leavePrivateChannelMessageEvent(LeaveMyPrivEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}

		$msg = $this->getLogoffMessage($sender);

		$event = new OfflineEvent(
			player: $sender,
			channel: 'priv',
		);
		$this->eventManager->fireEvent($event);

		$uid = $this->chatBot->getUid($sender);
		$e = new Online();
		$e->char = new Character($sender, $uid);
		$e->main = $this->altsController->getMainOf($sender);
		$e->online = false;
		if (isset($msg)) {
			$e->message = $msg;
		}
		$this->dispatchRoutableEvent($e);
		$this->guildController->lastLogoffMsgs[$e->main] = time();
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Updates the database when a character joins the private channel'
	)]
	public function joinPrivateChannelRecordEvent(JoinMyPrivEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		$this->onlineController->addPlayerToOnlineList(
			$sender,
			$this->config->general->orgName . ' Guests',
			'priv'
		);
	}

	#[NCA\Event(
		name: LeaveMyPrivEvent::EVENT_MASK,
		description: 'Updates the database when a character leaves the private channel'
	)]
	public function leavePrivateChannelRecordEvent(LeaveMyPrivEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		$this->onlineController->removePlayerFromOnlineList($sender, 'priv');
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Sends the online list to people as they join the private channel'
	)]
	public function joinPrivateChannelShowOnlineEvent(JoinMyPrivEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		$msg = $this->onlineController->getOnlineList();
		$this->chatBot->sendMassTell($msg, $sender);
	}

	#[NCA\Event(
		name: MemberAddEvent::EVENT_MASK,
		description: 'Send welcome message data/welcome.txt to new members'
	)]
	public function sendWelcomeMessage(MemberAddEvent $event): void {
		$welcomeFile = "{$this->config->paths->data}/welcome.txt";
		try {
			if (!$this->fs->exists($welcomeFile)) {
				return;
			}
			$content = $this->fs->read($welcomeFile);
		} catch (FilesystemException $e) {
			$this->logger->error('Error reading {file}: {error}', [
				'file' => $welcomeFile,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		}
		$msg = $this->welcomeMsgString;
		if (count($matches = Safe::pregMatch("/^(.*)<link>(.*?)<\/link>(.*)$/", $msg))) {
			$msg = (array)$this->text->makeBlob($matches[2], $content);
			foreach ($msg as &$part) {
				$part = "{$matches[1]}{$part}{$matches[3]}";
			}
		} else {
			$msg = $this->text->makeBlob('Welcome to <myname>!', $content);
		}
		$this->chatBot->sendMassTell($msg, $event->sender);
	}

	public function removeUser(string $name, string $sender): string {
		$name = ucfirst(strtolower($name));

		if (!$this->db->table(self::DB_TABLE)->where('name', $name)->delete()) {
			return "<highlight>{$name}<end> is not a member of this bot.";
		}
		unset($this->members[$name]);
		$this->buddylistManager->remove($name, 'member');
		$event = new MemberRemoveEvent(sender: $name);
		$this->eventManager->fireEvent($event);
		$audit = new Audit(
			actor: $sender,
			actee: $name,
			action: AccessManager::DEL_RANK,
			value: (string)$this->accessManager->getAccessLevels()['member'],
		);
		$this->accessManager->addAudit($audit);
		return "<highlight>{$name}<end> has been removed as a member of this bot.";
	}

	/** Check if the private channel is currently locked */
	public function isLocked(): bool {
		return strlen($this->lockReason) > 0;
	}

	/** Check if the private channel is currently locked for a character */
	public function isLockedFor(string $sender): bool {
		if (!strlen($this->lockReason)) {
			return false;
		}
		$alSender = $this->accessManager->getAccessLevelForCharacter($sender);
		$alRequired = $this->lockMinrank;
		return $this->accessManager->compareAccessLevels($alSender, $alRequired) < 0;
	}

	#[NCA\HandlesCommand('lastonline')]
	#[NCA\Help\Group('private-channel')]
	/** Check when a character and their alts were seen online for the last time */
	public function lastOnlineCommand(CmdContext $context, PCharacter $char): void {
		$uid = $this->chatBot->getUid($char());
		if ($uid === null) {
			$context->reply("Character {$char} doesn't exist.");
			return;
		}
		$main = $this->altsController->getMainOf($char());

		/** @var Collection<LastOnline> */
		$lastSeen = $this->db->table('last_online')
			->whereIn('name', $this->altsController->getAltsOf($main))
			->orderByDesc('dt')
			->asObj(LastOnline::class);
		if ($lastSeen->isEmpty()) {
			$context->reply("<highlight>{$char}<end> has never logged in.");
			return;
		}
		$blob = $lastSeen->map(function (LastOnline $info): string {
			if ($this->buddylistManager->isOnline($info->name)) {
				return "<highlight>{$info->name}<end> is currently <on>online<end>";
			}
			return "<highlight>{$info->name}<end> last seen at " . $this->util->date($info->dt);
		})->sort(static function (string $line1, string $line2): int {
			$oneHas = str_contains($line1, '<on>');
			$twoHas = str_contains($line2, '<on>');
			if ($oneHas === $twoHas) {
				return 0;
			}
			return $oneHas ? -1 : 1;
		})->join("\n");
		$msg = $this->text->makeBlob(
			"Last Logon Info for {$char}",
			$blob,
		);
		$context->reply($msg);
	}

	/** @return array{"admin-level": ?string, "c-admin-level": ?string, "access-level": ?string} */
	protected function getRankTokens(string $player): array {
		$tokens = [
			'access-level' => null,
			'admin-level' => null,
			'c-admin-level' => null,
		];
		$alRank = $this->accessManager->getAccessLevelForCharacter($player);
		$alName = ucfirst($this->accessManager->getDisplayName($alRank));
		$colors = $this->onlineController;
		switch ($alRank) {
			case 'superadmin':
				$tokens['admin-level'] = $alName;
				$tokens['c-admin-level'] = "{$colors->rankColorSuperadmin}{$alName}<end>";
				break;
			case 'admin':
				$tokens['admin-level'] = $alName;
				$tokens['c-admin-level'] = "{$colors->rankColorAdmin}{$alName}<end>";
				break;
			case 'mod':
				$tokens['admin-level'] = $alName;
				$tokens['c-admin-level'] = "{$colors->rankColorMod}{$alName}<end>";
				break;
			default:
				$raidRank = $this->raidRankController->getSingleAccessLevel($player);
				if (isset($raidRank)) {
					$alName = ucfirst($this->accessManager->getDisplayName($raidRank));
					$tokens['admin-level'] = $alName;
					$tokens['c-admin-level'] = "{$colors->rankColorRaid}{$alName}<end>";
				}
		}
		$tokens['access-level'] = $alName;
		return $tokens;
	}

	/** @return array<string, string|int|null> */
	protected function getTokensForJoinLeave(string $player, ?Player $whois, ?AltInfo $altInfo): array {
		$altInfo ??= $this->altsController->getAltInfo($player);
		$tokens = [
			'name' => $player,
			'c-name' => "<highlight>{$player}<end>",
			'first-name' => $whois?->firstname,
			'last-name' => $whois?->lastname,
			'level' => $whois?->level,
			'c-level' => $whois ? "<highlight>{$whois->level}<end>" : null,
			'ai-level' => $whois?->ai_level,
			'c-ai-level' => $whois ? "<green>{$whois->ai_level}<end>" : null,
			'prof' => $whois?->profession?->value,
			'c-prof' => $whois?->profession?->inColor(),
			'profession' => $whois?->profession?->value,
			'c-profession' => $whois?->profession?->inColor(),
			'org' => $whois?->guild,
			'c-org' => $whois
				? '<' . strtolower($whois->faction ?? 'highlight') . ">{$whois->guild}<end>"
				: null,
			'org-rank' => $whois?->guild_rank,
			'breed' => $whois?->breed,
			'faction' => $whois?->faction,
			'c-faction' => $whois
				? '<' . strtolower($whois->faction ?? 'highlight') . ">{$whois->faction}<end>"
				: null,
			'gender' => $whois?->gender,
			'channel-name' => 'the private channel',
			'whois' => $player,
			'short-prof' => null,
			'c-short-prof' => null,
			'main' => null,
			'c-main' => null,
			'nick' => $altInfo->getNick(),
			'c-nick' => $altInfo->getDisplayNick(),
			'alt-of' => null,
			'alt-list' => null,
			'logon-msg' => $this->preferences->get($player, 'logon_msg'),
			'logoff-msg' => $this->preferences->get($player, 'logoff_msg'),
		];
		if (!isset($tokens['logon-msg']) || !strlen($tokens['logon-msg'])) {
			$tokens['logon-msg'] = null;
		}
		if (!isset($tokens['logoff-msg']) || !strlen($tokens['logoff-msg'])) {
			$tokens['logoff-msg'] = null;
		}
		if (isset($tokens['c-nick'])) {
			$tokens['c-nick'] = "<highlight>{$tokens['c-nick']}";
		}
		$ranks = $this->getRankTokens($player);
		$tokens = array_merge($tokens, $ranks);

		if (isset($whois)) {
			$tokens['whois'] = $this->playerManager->getInfo($whois);
			if (isset($whois->profession)) {
				$tokens['short-prof'] = $whois->profession->short();
				$tokens['c-short-prof'] = "<highlight>{$tokens['short-prof']}<end>";
			}
		}
		if ($this->settingManager->getBool('guild_channel_status') === false) {
			$tokens['channel-name'] = '<myname>';
		}
		if ($altInfo->main !== $player) {
			$tokens['main'] = $altInfo->main;
			$tokens['c-main'] = "<highlight>{$altInfo->main}<end>";
			$tokens['c-nick'] ??= $tokens['c-main'];
			$tokens['alt-of'] = "Alt of <highlight>{$tokens['c-nick']}<end>";
		}
		if (count($altInfo->getAllValidatedAlts()) > 0) {
			$blob = $altInfo->getAltsBlob(true);
			$tokens['alt-list'] = (string)((array)$blob)[0];
		}
		return $tokens;
	}

	protected function getLogonMessageForPlayer(?Player $whois, string $player, AltInfo $altInfo): string {
		$tokens = $this->getTokensForJoinLeave($player, $whois, $altInfo);
		$joinMessage = $this->text->renderPlaceholders($this->privJoinMessage, $tokens);
		$joinMessage = Safe::pregReplace(
			'/&lt;([a-z]+)&gt;/',
			'<$1>',
			$joinMessage
		);
		assert(is_string($joinMessage));

		return $joinMessage;
	}

	/**
	 * Only keep characters currently in the raid
	 *
	 * @param OnlinePlayer[] $chars
	 *
	 * @return array{?string,OnlinePlayer[]}
	 */
	private function filterRaid(array $chars): array {
		$raid = $this->raidController->raid;
		if (!isset($raid)) {
			return [RaidController::ERR_NO_RAID, []];
		}
		$chars = array_values(
			array_filter(
				$chars,
				static function (OnlinePlayer $char) use ($raid): bool {
					return isset($raid->raiders[$char->name])
						&& !isset($raid->raiders[$char->name]->left);
				}
			)
		);
		return [null, $chars];
	}

	private function addUser(string $name, string $sender): string {
		$autoInvite = $this->autoinviteDefault;
		$name = ucfirst(strtolower($name));
		$uid = $this->chatBot->getUid($name);
		if ($this->config->main->character === $name) {
			throw new Exception('You cannot add the bot as a member of itself.');
		} elseif ($uid === null) {
			throw new Exception("Character <highlight>{$name}<end> does not exist.");
		}
		$maxBuddies = $this->chatBot->getBuddyListSize();
		$numBuddies = $this->buddylistManager->getUsedBuddySlots();
		if ($autoInvite && $numBuddies >= $maxBuddies) {
			throw new Exception(
				'The buddylist already contains '.
				"{$numBuddies}/{$maxBuddies} characters. ".
				'In order to be able to add more members, you need '.
				'to setup AOChatProxy (https://github.com/Nadybot/aochatproxy).'
			);
		}
		// always add in case they were removed from the buddy list for some reason
		$this->buddylistManager->addName($name, 'member');
		if ($this->db->table(self::DB_TABLE)->where('name', $name)->exists()) {
			return "<highlight>{$name}<end> is already a member of this bot.";
		}
		$memberObj = new Member(
			name: $name,
			added_by: $sender,
			autoinv: $autoInvite ? 1 : 0,
		);
		$this->db->insert(self::DB_TABLE, $memberObj, null);
		$this->members[$name] = $memberObj;
		$event = new MemberAddEvent(sender: $name);
		$this->eventManager->fireEvent($event);
		$audit = new Audit(
			actor: $sender,
			actee: $name,
			action: AccessManager::ADD_RANK,
			value: (string)$this->accessManager->getAccessLevels()['member'],
		);
		$this->accessManager->addAudit($audit);
		return "<highlight>{$name}<end> has been added as a member of this bot.";
	}

	private function kickChar(string $name): void {
		if (null === ($uid = $this->chatBot->getUid($name))) {
			return;
		}
		$this->chatBot->sendPackage(
			package: new Package\Out\PrivateChannelKick(charId: $uid)
		);
	}

	private function inviteChar(int|string $name): void {
		if (is_int($name)) {
			$uid = $name;
		} elseif (null === ($uid = $this->chatBot->getUid($name))) {
			return;
		}
		$this->chatBot->sendPackage(
			package: new Package\Out\PrivateChannelInvite(charId: $uid)
		);
	}
}
