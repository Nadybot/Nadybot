<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\Modules\ALTS\AltInfo;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	Events\ConnectEvent,
	Events\Event,
	Events\LogoffEvent,
	Events\LogonEvent,
	Events\OrgMsgChannelMsgEvent,
	MessageHub,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Routing\Character,
	Routing\Events\Base,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\Source,
	Safe,
	SettingManager,
	Text,
	Types\SettingMode,
	Util,
};
use Nadybot\Modules\ONLINE_MODULE\{Online as DBOnline, OnlineController};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Derroylo (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Base'),
	NCA\DefineCommand(
		command: 'logon',
		accessLevel: 'guild',
		description: 'Set logon message',
	),
	NCA\DefineCommand(
		command: 'logoff',
		accessLevel: 'guild',
		description: 'Set logoff message',
	),
	NCA\DefineCommand(
		command: 'lastseen',
		accessLevel: 'guild',
		description: 'Shows the last logoff time of a character',
	),
	NCA\DefineCommand(
		command: 'recentseen',
		accessLevel: 'guild',
		description: 'Shows org members who have logged off recently',
	),
	NCA\DefineCommand(
		command: 'notify',
		accessLevel: 'mod',
		description: 'Adds a character to the notify list manually',
	),
	NCA\DefineCommand(
		command: 'updateorg',
		accessLevel: 'mod',
		description: 'Force an update of the org roster',
	),
	NCA\DefineCommand(
		command: 'orgstats',
		accessLevel: 'guild',
		description: 'Get statistics about the organization',
	),
]
class GuildController extends ModuleInstance {
	private const CONSECUTIVE_BAD_UPDATES = 2;

	/** Maximum characters a logon message can have */
	#[NCA\Setting\Number(options: [100, 200, 300, 400])]
	public int $maxLogonMsgSize = 200;

	/** Maximum characters a logoff message can have */
	#[NCA\Setting\Number(options: [100, 200, 300, 400])]
	public int $maxLogoffMsgSize = 200;

	/** Suppress alt logon/logoff messages during the interval */
	#[NCA\Setting\TimeOrOff(
		options: ['off', '5m', '15m', '1h', '1d'],
	)]
	public int $suppressLogonLogoff = 0;

	/** Message when an org member logs off */
	#[NCA\Setting\Template(
		options: [
			'{c-name} logged off{?logoff-msg: - {logoff-msg}}{!logoff-msg:.}',
		],
		help: 'org_logon_message.txt'
	)]
	public string $orgLogoffMessage = '{c-name} logged off{?logoff-msg: - {logoff-msg}}{!logoff-msg:.}';

	/** Message when an org member logs on */
	#[NCA\Setting\Template(
		options: [
			'{whois} logged on{?main:. {alt-of}}{?logon-msg: - {logon-msg}}',
			'{whois} logged on{?alt-list:. {alt-list}}{?logon-msg: - {logon-msg}}',
			'{c-name}{?main: ({main})}{?level: - {c-level}/{c-ai-level} {short-prof}} logged on{?logon-msg: - {logon-msg}}{!logon-msg:.}',
			'{c-name}{?nick: ({c-nick})}{!nick:{?main: ({main})}}{?level: - {c-level}/{c-ai-level} {short-prof}} logged on{?logon-msg: - {logon-msg}}{!logon-msg:.}',
			'<on>+<end> {c-name}{?main: ({main})}{?level: - {c-level}/{c-ai-level} {short-prof}}{?org: - {org-rank} of {c-org}}{?admin-level: :: {c-admin-level}}',
			'<on>+<end> {c-name}{?nick: ({c-nick})}{!nick:{?main: ({main})}}{?level: - {c-level}/{c-ai-level} {short-prof}}{?org: - {org-rank} of {c-org}}{?admin-level: :: {c-admin-level}}',
			'{name}{?level: :: {c-level}/{c-ai-level} {short-prof}}{?org: :: {c-org}} logged on{?admin-level: :: {c-admin-level}}{?main: :: {c-main}}{?logon-msg: :: {logon-msg}}',
		],
		help: 'org_logon_message.txt'
	)]
	public string $orgLogonMessage = '{whois} logged on{?alt-list:. {alt-list}}{?logon-msg: - {logon-msg}}';

	/** @var array<string,int> */
	public array $lastLogonMsgs = [];

	/** @var array<string,int> */
	public array $lastLogoffMsgs = [];

	/** The last detected org name */
	#[NCA\Setting\Text(mode: SettingMode::NoEdit)]
	public string $lastOrgName = Nadybot::UNKNOWN_ORG;

	/** Number of skipped roster updates, because they were likely bad */
	#[NCA\Setting\Number(mode: SettingMode::NoEdit)]
	public int $numOrgUpdatesSkipped = 0;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private OnlineController $onlineController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private GuildManager $guildManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private Preferences $preferences;

	#[NCA\Setup]
	public function setup(): void {
		$this->loadGuildMembers();
	}

	/** See your current logon message */
	#[NCA\HandlesCommand('logon')]
	public function logonMessageShowCommand(CmdContext $context): void {
		$logonMessage = $this->preferences->get($context->char->name, 'logon_msg');

		if ($logonMessage === null || $logonMessage === '') {
			$msg = 'Your logon message has not been set.';
		} else {
			$msg = "{$context->char->name} logon: {$logonMessage}";
		}
		$context->reply($msg);
	}

	/** Set your new logon message. 'clear' to remove it */
	#[NCA\HandlesCommand('logon')]
	public function logonMessageSetCommand(CmdContext $context, string $logonMessage): void {
		if ($logonMessage === 'clear') {
			$this->preferences->save($context->char->name, 'logon_msg', '');
			$msg = 'Your logon message has been cleared.';
		} elseif (strlen($logonMessage) <= $this->maxLogonMsgSize) {
			$this->preferences->save($context->char->name, 'logon_msg', $logonMessage);
			$msg = 'Your logon message has been set.';
		} else {
			$msg = 'Your logon message is too large. '.
				'Your logon message may contain a maximum of '.
				"{$this->maxLogonMsgSize} characters.";
		}
		$context->reply($msg);
	}

	/** See your current logon message */
	#[NCA\HandlesCommand('logoff')]
	public function logoffMessageShowCommand(CmdContext $context): void {
		$logoffMessage = $this->preferences->get($context->char->name, 'logoff_msg');

		if ($logoffMessage === null || $logoffMessage === '') {
			$msg = 'Your logoff message has not been set.';
		} else {
			$msg = "{$context->char->name} logoff: {$logoffMessage}";
		}
		$context->reply($msg);
	}

	/** Set your new logoff message. 'clear' to remove it */
	#[NCA\HandlesCommand('logoff')]
	public function logoffMessageSetCommand(CmdContext $context, string $logoffMessage): void {
		if ($logoffMessage === 'clear') {
			$this->preferences->save($context->char->name, 'logoff_msg', '');
			$msg = 'Your logoff message has been cleared.';
		} elseif (strlen($logoffMessage) <= $this->maxLogoffMsgSize) {
			$this->preferences->save($context->char->name, 'logoff_msg', $logoffMessage);
			$msg = 'Your logoff message has been set.';
		} else {
			$msg = 'Your logoff message is too large. '.
				'Your logoff message may contain a maximum of '.
				"{$this->maxLogoffMsgSize} characters.";
		}
		$context->reply($msg);
	}

	/** Check when a member of your org was last seen online by the bot */
	#[NCA\HandlesCommand('lastseen')]
	public function lastSeenCommand(CmdContext $context, PCharacter $name): void {
		$uid = $this->chatBot->getUid($name());
		if ($uid === null) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$altInfo = $this->altsController->getAltInfo($name());
		$onlineAlts = $altInfo->getOnlineAlts();

		$blob = '';
		foreach ($onlineAlts as $onlineAlt) {
			$blob .= "<highlight>{$onlineAlt}<end> is currently online.\n";
		}

		$alts = $altInfo->getAllAlts();

		$data = $this->db->table(OrgMember::getTable())
			->whereIn('name', $alts)
			->where('mode', '!=', 'del')
			->orderByDesc('logged_off')
			->asObj(OrgMember::class);

		foreach ($data as $row) {
			if (in_array($row->name, $onlineAlts, true)) {
				// skip
				continue;
			} elseif (!isset($row->logged_off) || $row->logged_off === 0) {
				$blob .= "<highlight>{$row->name}<end> has never logged on.\n";
			} else {
				$blob .= "<highlight>{$row->name}<end> last seen at " . Util::date($row->logged_off) . ".\n";
			}
		}

		$msg = "Character <highlight>{$name}<end> is not a member of the org.";
		if ($data->count() !== 0) {
			$msg = $this->text->makeBlob("Last Seen Info for {$altInfo->main}", $blob);
		}

		$context->reply($msg);
	}

	/**
	 * Show all org members who have logged on within a specified amount of time
	 *
	 * This will take into account each member's alts when reporting their last logon time
	 */
	#[NCA\HandlesCommand('recentseen')]
	#[NCA\Help\Example(
		command: '<symbol>recentseen 1d',
		description: 'List all org members who logged on within 1 day'
	)]
	public function recentSeenCommand(CmdContext $context, PDuration $duration): void {
		if (!$this->isGuildBot()) {
			$context->reply('The bot must be in an org.');
			return;
		}

		$time = $duration->toSecs();
		if ($time < 1) {
			$msg = 'You must enter a valid time parameter.';
			$context->reply($msg);
			return;
		}

		$timeString = Util::unixtimeToReadable($time, false);
		$time = time() - $time;

		$members = $this->db->table(OrgMember::getTable())
			->where('mode', '!=', 'del')
			->where('logged_off', '>', $time)
			->orderByDesc('logged_off')
			->asObj(OrgMember::class)
			->map(function (OrgMember $member): RecentOrgMember {
				return new RecentOrgMember(
					main: $this->altsController->getMainOf($member->name),
					name: $member->name,
					mode: $member->mode,
					logged_off: $member->logged_off,
				);
			});

		if ($members->count() === 0) {
			$context->reply('No members recorded.');
			return;
		}
		$members = $members->groupBy('main');

		$numRecentCount = 0;
		$highlight = false;

		$blob = "Org members who have logged off within the last <highlight>{$timeString}<end>.\n\n";

		$prevToon = '';
		foreach ($members as $main => $memberAlts) {
			$member = $memberAlts->firstOrFail();

			if ($member->main === $prevToon) {
				continue;
			}
			$prevToon = $member->main;
			$numRecentCount++;
			$alts = Text::makeChatcmd('alts', "/tell <myname> alts {$member->main}");
			$logged = $member->logged_off??time();
			$lastToon = $member->name;

			$character = "<pagebreak><highlight>{$member->main}<end> [{$alts}]\n".
				"<tab>Last seen as {$lastToon} on ".
				Util::date($logged) . "\n\n";
			if ($highlight === true) {
				$blob .= "<highlight>{$character}<end>";
				$highlight = false;
			} else {
				$blob .= $character;
				$highlight = true;
			}
		}
		$msg = $this->text->makeBlob("{$numRecentCount} recently seen org members", $blob);
		$context->reply($msg);
	}

	/**
	 * Manually add a character to the notify list
	 *
	 * Do this if someone is an org member, but not in the org roster yet
	 */
	#[NCA\HandlesCommand('notify')]
	public function notifyAddCommand(
		CmdContext $context,
		#[NCA\Str('on', 'add')] string $action,
		PCharacter $char
	): void {
		$name = $char();
		$uid = $this->chatBot->getUid($name);

		if ($uid === null) {
			$msg = "<highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$mode = $this->db->table(OrgMember::getTable())
			->where('name', $name)
			->select('mode')
			->pluckStrings('mode')->first();

		if ($mode !== null && $mode !== 'del') {
			$msg = "<highlight>{$name}<end> is already on the Notify list.";
			$context->reply($msg);
			return;
		}
		$this->db->upsert(new OrgMember(
			name: $name,
			mode: 'add'
		));

		if ($this->buddylistManager->isOnline($name)) {
			$this->db->insert(new DBOnline(
				name: $name,
				channel: $this->db->getMyguild(),
				channel_type: 'guild',
				added_by: $this->db->getBotname(),
				dt: time(),
			));
		}
		$this->buddylistManager->addName($name, 'org');
		$this->chatBot->guildmembers[$name] = 6;
		$msg = "<highlight>{$name}<end> has been added to the Notify list.";

		$context->reply($msg);
	}

	/** Manually remove a character from the notify list */
	#[NCA\HandlesCommand('notify')]
	public function notifyRemoveCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $char
	): void {
		$name = $char();
		$uid = $this->chatBot->getUid($name);

		if ($uid === null) {
			$msg = "<highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$mode = $this->db->table(OrgMember::getTable())
			->where('name', $name)
			->select('mode')
			->pluckStrings('mode')->first();

		if ($mode === null) {
			$msg = "<highlight>{$name}<end> is not on the guild roster.";
		} elseif ($mode === 'del') {
			$msg = "<highlight>{$name}<end> has already been removed from the Notify list.";
		} else {
			$this->db->table(OrgMember::getTable())
				->where('name', $name)
				->update(['mode' => 'del']);
			$this->delMemberFromOnline($name);
			$this->buddylistManager->remove($name, 'org');
			unset($this->chatBot->guildmembers[$name]);
			$msg = "Removed <highlight>{$name}<end> from the Notify list.";
		}

		$context->reply($msg);
	}

	/** Force an update of the org roster */
	#[NCA\HandlesCommand('updateorg')]
	public function updateorgCommand(CmdContext $context): void {
		$context->reply('Starting Roster update');
		try {
			$this->updateMyOrgRoster(true);
		} catch (Throwable $e) {
			$context->reply('There was an error during the roster update: '.
				$e->getMessage());
			return;
		}
		$context->reply('Finished Roster update');
	}

	public function updateMyOrgRoster(bool $forceUpdate=false): void {
		if (!$this->isGuildBot() || !isset($this->config->orgId)) {
			return;
		}
		$this->logger->notice('Starting Roster update');
		$org = $this->guildManager->byId($this->config->orgId, $this->config->main->dimension, $forceUpdate);
		$this->updateRosterForGuild($org);
	}

	public function dispatchRoutableEvent(Base $event): void {
		$abbr = $this->settingManager->getString('relay_guild_abbreviation');
		$re = new RoutableEvent(
			type: RoutableEvent::TYPE_EVENT,
			path: [new Source(
				Source::ORG,
				$this->config->general->orgName,
				($abbr === 'none') ? null : $abbr
			)],
			data: $event,
		);
		$this->messageHub->handle($re);
	}

	/** Get statistics about the organization */
	#[NCA\HandlesCommand('orgstats')]
	public function orgstatsCommand(
		CmdContext $context,
		#[NCA\Str('online')] ?string $onlineOnly,
	): void {
		if (!$this->isGuildBot() || !isset($this->config->orgId)) {
			$context->reply('The bot must be in an org.');
			return;
		}

		$org = $this->guildManager->byId($this->config->orgId, $this->config->main->dimension, false);
		$members = $this->db->table(OrgMember::getTable(), 'om')
			->join(Player::getTable(as: 'p'), 'om.name', '=', 'p.name')
			->select('p.*')
			->asObj(Player::class);
		if ($members->isEmpty()) {
			$context->reply("I don't have data for any of our fellow org members.");
			return;
		}
		if (isset($onlineOnly)) {
			$members = $members->filter(function (Player $player): bool {
				return $this->buddylistManager->isOnline($player->name) ?? false;
			});
			if ($members->isEmpty()) {
				$context->reply("There's no one online but me right now.");
				return;
			}
		}

		$statsFunc = static function (Collection $players, string $key) use ($members): string {
			$count = $players->count();
			$percentage = Text::alignNumber(
				(int)round($count * 100 / $members->count(), 0),
				3
			);
			return "\n<tab>{$percentage} % <highlight>{$key}<end>: {$count} ".
				Text::pluralize('member', $count).
				', level ' . $players->min('level') . ' / <highlight>'.
				round(($players->avg('level') ?? 0), 0) . '<end> / '.
				$players->max('level');
		};
		$tlFunc = static function (Player $p): string {
			return 'TL ' . Util::levelToTL($p->level ?? 1);
		};

		$blob = '<header2>' . ($org->orgname ?? $this->config->general->orgName) . "<end>\n";
		if (isset($org)) {
			$blob .= '<tab><highlight>Faction<end>: ' . $org->orgside->inColor() . "\n".
			"<tab><highlight>Government<end>: {$org->governing_form->value}\n";
		}
		$blob .= '<tab><highlight>Members<end>: ' . $members->count() . "\n".
			'<tab><highlight>Min level<end>: ' . $members->min('level') . "\n".
			'<tab><highlight>Avg level<end>: ' . round(($members->avg('level') ?? 0), 0) . "\n".
			'<tab><highlight>Max level<end>: ' . $members->max('level') . "\n\n".
			'<header2>Numbers by breed<end>'.
			$members->sortBy('breed')->groupBy('breed')
			->map($statsFunc)->join('').
			"\n\n<header2>Numbers by profession<end>".
			$members->sortBy('profession')->groupBy('profession')
			->map($statsFunc)->join('').
			"\n\n<header2>Numbers by gender<end>".
			$members->sortBy('gender')->groupBy('gender')
			->map($statsFunc)->join('').
			"\n\n<header2>Numbers by title level<end>".
			$members->sortBy('level')->groupBy($tlFunc)
			->map($statsFunc)->join('');
		$msg = $this->text->makeBlob('Org statistics', $blob);
		$context->reply($msg);
	}

	#[NCA\Event(
		name: 'timer(24hrs)',
		description: 'Download guild roster xml and update guild members'
	)]
	public function downloadOrgRosterEvent(Event $eventObj): void {
		$this->updateMyOrgRoster(false);
	}

	#[NCA\Event(
		name: OrgMsgChannelMsgEvent::EVENT_MASK,
		description: 'Automatically update guild roster as characters join and leave the guild'
	)]
	public function autoNotifyOrgMembersEvent(OrgMsgChannelMsgEvent $eventObj): void {
		$message = $eventObj->message;
		if (count($arr = Safe::pregMatch('/^(.+) invited (.+) to your organization.$/', $message))) {
			$name = ucfirst(strtolower($arr[2]));

			if (
				$this->buddylistManager->isOnline($name) === true
				&& $this->db->table(DBOnline::getTable())
					->where('name', $name)
					->where('channel_type', 'guild')
					->where('added_by', $this->db->getBotname())
					->doesntExist()
			) {
				$this->db->insert(new DBOnline(
					name: $name,
					channel: $this->db->getMyguild(),
					channel_type: 'guild',
					added_by: $this->db->getBotname(),
					dt: time(),
				));
			}
			$this->db->table(OrgMember::getTable())
				->upsert(['mode' => 'add', 'name' => $name], 'name');
			$this->buddylistManager->addName($name, 'org');
			$this->chatBot->guildmembers[$name] = 6;

			// update character info
			$this->playerManager->byName($name);
		} elseif (
			count($arr = Safe::pregMatch('/^(.+) kicked (?<char>.+) from your organization.$/', $message))
			|| count($arr = Safe::pregMatch('/^(.+) removed inactive character (?<char>.+) from your organization.$/', $message))
			|| count($arr = Safe::pregMatch('/^(?<char>.+) just left your organization.$/', $message))
			|| count($arr = Safe::pregMatch('/^(?<char>.+) kicked from organization \\(alignment changed\\).$/', $message))
		) {
			$name = ucfirst(strtolower($arr['char']));

			$this->db->table(OrgMember::getTable())
				->where('name', $name)
				->update(['mode' => 'del']);
			$this->delMemberFromOnline($name);

			unset($this->chatBot->guildmembers[$name]);
			$this->buddylistManager->remove($name, 'org');
		}
	}

	public function canShowLogonMessageForChar(string $char): bool {
		if ($this->suppressLogonLogoff === 0) {
			return true;
		}
		$altInfo = $this->altsController->getAltInfo($char);

		$alreadyLoggedIn = $this->numAltsOnline($char) > 1;
		if (!$alreadyLoggedIn) {
			return true;
		}
		$lastAccountLogonMsg = $this->lastLogonMsgs[$altInfo->main]??0;
		$lastLogonMsgTooRecent = (time() - $lastAccountLogonMsg) < $this->suppressLogonLogoff;
		return $lastLogonMsgTooRecent === false;
	}

	public function getLogonMessage(string $player): ?string {
		if (!$this->canShowLogonMessageForChar($player)) {
			return null;
		}
		$whois = $this->playerManager->byName($player);
		return $this->getLogonMessageForPlayer($whois, $player);
	}

	public function getLogonMessageForPlayer(?Player $whois, string $player): string {
		$tokens = $this->getTokensForLogonLogoff($player, $whois, null);
		$logonMessage = Text::renderPlaceholders($this->orgLogonMessage, $tokens);
		$logonMessage = Safe::pregReplace(
			'/&lt;([a-z]+)&gt;/',
			'<$1>',
			$logonMessage
		);

		return $logonMessage;
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Shows an org member logon in chat'
	)]
	public function orgMemberLogonMessageEvent(LogonEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| $eventObj->wasOnline !== false) {
			return;
		}
		$msg = $this->getLogonMessage($sender);
		$uid = $this->chatBot->getUid($sender);
		$eMain = $this->altsController->getMainOf($sender);
		$e = new Online(
			char: new Character($sender, $uid),
			main: $eMain,
			online: true,
			message: $msg,
		);
		$this->dispatchRoutableEvent($e);
		if (isset($msg)) {
			$this->chatBot->sendGuild($msg, true);
			$this->lastLogonMsgs[$eMain] = time();
		}
	}

	public function canShowLogoffMessageForChar(string $char): bool {
		if ($this->suppressLogonLogoff === 0) {
			return true;
		}
		$altInfo = $this->altsController->getAltInfo($char);
		$stillLoggedIn = $this->numAltsOnline($char) > 0;
		if (!$stillLoggedIn) {
			return true;
		}
		$lastAccountLogoffMsg = $this->lastLogoffMsgs[$altInfo->main]??0;
		$lastLogoffMsgTooRecent = (time() - $lastAccountLogoffMsg) < $this->suppressLogonLogoff;
		return $lastLogoffMsgTooRecent === false;
	}

	public function getLogoffMessage(string $player): ?string {
		if (!$this->canShowLogoffMessageForChar($player)) {
			return null;
		}
		$altInfo = $this->altsController->getAltInfo($player);
		$whois = $this->playerManager->byName($player);

		$tokens = $this->getTokensForLogonLogoff($player, $whois, $altInfo);
		$logoffMessage = Text::renderPlaceholders($this->orgLogoffMessage, $tokens);
		$logoffMessage = Safe::pregReplace(
			'/&lt;([a-z]+)&gt;/',
			'<$1>',
			$logoffMessage
		);

		return $logoffMessage;
	}

	#[NCA\Event(
		name: LogoffEvent::EVENT_MASK,
		description: 'Shows an org member logoff in chat'
	)]
	public function orgMemberLogoffMessageEvent(LogoffEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| $eventObj->wasOnline !== true) {
			return;
		}

		$uid = $this->chatBot->getUid($sender);

		$msg = $this->getLogoffMessage($sender);
		$eMain = $this->altsController->getMainOf($sender);
		$e = new Online(
			char: new Character($sender, $uid),
			main: $eMain,
			online: false,
			message: $msg,
		);
		$this->dispatchRoutableEvent($e);
		$this->lastLogoffMsgs[$eMain] = time();
		if ($msg === null) {
			return;
		}

		$this->chatBot->sendGuild($msg, true);
	}

	#[NCA\Event(
		name: LogoffEvent::EVENT_MASK,
		description: 'Record org member logoff for lastseen command'
	)]
	public function orgMemberLogoffRecordEvent(LogoffEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
		) {
			return;
		}
		$this->db->table(OrgMember::getTable())
			->where('name', $sender)
			->update(['logged_off' => time()]);
	}

	public function isGuildBot(): bool {
		return strlen($this->config->general->orgName) > 0
			&& isset($this->config->orgId);
	}

	#[NCA\Event(
		name: ConnectEvent::EVENT_MASK,
		description: 'Verifies that org name is correct'
	)]
	public function verifyOrgNameEvent(ConnectEvent $eventObj): void {
		if ($this->config->general->orgName === '') {
			return;
		}
		if (isset($this->config->orgId)) {
			$this->logger->warning("Org name '{org_name}' specified, but bot does not appear to belong to an org", [
				'org_name' => $this->config->general->orgName,
			]);
			return;
		}
		$orgChannel = $this->chatBot->getOrgGroup();
		if (isset($orgChannel) && $orgChannel->name !== Nadybot::UNKNOWN_ORG && $orgChannel->name !== $this->config->general->orgName) {
			$this->logger->warning("Org name '{org_name}' specified, but bot belongs to org '{org_channel}'", [
				'org_name' => $this->config->general->orgName,
				'org_channel' => $orgChannel,
			]);
		}
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
		}
		$tokens['access-level'] = $alName;
		return $tokens;
	}

	/** @return array<string,string|int|null> */
	protected function getTokensForLogonLogoff(string $player, ?Player $whois, ?AltInfo $altInfo): array {
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
			'c-org' => $whois?->faction->inColor($whois?->guild),
			'org-rank' => $whois?->guild_rank,
			'breed' => $whois?->breed,
			'faction' => $whois?->faction->value,
			'c-faction' => $whois?->faction->inColor(),
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
			$tokens['alt-of'] = "Alt of <highlight>{$tokens['c-nick']}<end>";
		}
		if (count($altInfo->getAllValidatedAlts()) > 0) {
			$blob = $altInfo->getAltsBlob(true);
			$tokens['alt-list'] = ((array)$blob)[0];
		}
		return $tokens;
	}

	/** Remove someone from the online list that we added for "guild" */
	protected function delMemberFromOnline(string $member): int {
		return $this->db->table(DBOnline::getTable())
			->where('name', $member)
			->where('channel_type', 'guild')
			->where('added_by', $this->db->getBotname())
			->delete();
	}

	/**
	 * Count the number of alts of 1 player that are in the private chat or
	 * in this bot's org and online
	 *
	 * @param string $char Any char to test for
	 *
	 * @return int Number of total characters online, including $char
	 */
	private function numAltsOnline(string $char): int {
		$altInfo = $this->altsController->getAltInfo($char);

		/**
		 * All alts/main of $char that are in the private channel
		 *
		 * @var list<string>
		 */
		$altsInChat = array_values(
			array_filter(
				$altInfo->getAllValidatedAlts(),
				function (string $alt): bool {
					return isset($this->chatBot->chatlist[$alt]);
				}
			)
		);

		/** @var list<string> */
		$altsInOrgOnline = [];
		if ($this->isGuildBot()) {
			$altsInOrgOnline = array_values(
				array_filter(
					$altInfo->getOnlineAlts(),
					function (string $char): bool {
						return isset($this->chatBot->guildmembers[$char]);
					}
				)
			);
		}
		$altsInOrgOnline = array_unique([...$altsInOrgOnline, ...$altsInChat]);
		return count($altsInOrgOnline);
	}

	private function loadGuildMembers(): void {
		$this->chatBot->guildmembers = [];
		$members = $this->db->table(OrgMember::getTable())
			->where('mode', '!=', 'del')
			->orderBy('name')
			->asObj(OrgMember::class);
		$players = $this->playerManager
			->searchByNames($this->db->getDim(), ...$members->pluck('name')->toArray());
		$players->each(function (Player $player): void {
			$this->chatBot->guildmembers[$player->name] = $player->guild_rank_id ?? 6;
		});
	}

	private function updateRosterForGuild(?Guild $org): void {
		// Check if guild xml file is correct if not abort
		if ($org === null) {
			$this->logger->error('Error downloading the guild roster xml file');
			return;
		}

		if (count($org->members) === 0) {
			$this->logger->error('Guild xml file has no members! Aborting roster update.');
			return;
		}
		$dbEntries = [];

		// Save the current org_members table in a var
		$data = $this->db->table(OrgMember::getTable())->asObj(OrgMember::class);

		// If the update would remove over 30% of the org members,
		// only do this, if this happens 2 times in a row.
		// This way, we avoid deleting our guild members if
		// Funcom sends us incomplete data
		$removedPercent = 0;
		if ($data->count() > 0) {
			$removedPercent = (int)floor(100 - (count($org->members) / $data->count()) * 100);
		}
		if ($removedPercent > 30 && $this->numOrgUpdatesSkipped < self::CONSECUTIVE_BAD_UPDATES) {
			$this->logger->warning(
				'Org update would remove {percent}% of the org members - skipping for now',
				[
					'percent' => $removedPercent,
				]
			);
			$this->settingManager->save(
				'num_org_updates_skipped',
				$this->numOrgUpdatesSkipped + 1
			);
			return;
		}
		$this->settingManager->save('num_org_updates_skipped', 0);
		// @phpstan-ignore-next-line
		if ($data->count() > 0 || (count($org->members) === 0)) {
			foreach ($data as $row) {
				$dbEntries[$row->name] = [
					'name' => $row->name,
					'mode' => $row->mode,
				];
			}
		}

		$this->db->awaitBeginTransaction();

		$this->chatBot->ready = false;

		// Going through each member of the org and add or update his/her
		foreach ($org->members as $member) {
			// don't do anything if $member is the bot itself
			if (strtolower($member->name) === strtolower($this->config->main->character)) {
				continue;
			}

			// If there exists already data about the character just update him/her
			if (isset($dbEntries[$member->name])) {
				if ($dbEntries[$member->name]['mode'] === 'del') {
					// members who are not on notify should not be on the buddy list but should remain in the database
					$this->buddylistManager->remove($member->name, 'org');
					unset($this->chatBot->guildmembers[$member->name]);
				} else {
					// add org members who are on notify to buddy list
					EventLoop::queue($this->buddylistManager->addName(...), $member->name, 'org');
					$this->chatBot->guildmembers[$member->name] = $member->guild_rank_id ?? 0;

					// if member was added to notify list manually, switch mode to org and let guild roster update from now on
					if ($dbEntries[$member->name]['mode'] === 'add') {
						$this->db->table(OrgMember::getTable())
							->where('name', $member->name)
							->update(['mode' => 'org']);
					}
				}
				// else insert his/her data
			} else {
				// add new org members to buddy list
				EventLoop::queue($this->buddylistManager->addName(...), $member->name, 'org');
				$this->chatBot->guildmembers[$member->name] = $member->guild_rank_id ?? 0;

				$this->db->insert(new OrgMember(
					name: $member->name,
					mode: 'org',
				));
			}
			unset($dbEntries[$member->name]);
		}

		$this->db->commit();

		// remove buddies who are no longer org members
		foreach ($dbEntries as $buddy) {
			if ($buddy['mode'] !== 'add') {
				$this->delMemberFromOnline($buddy['name']);
				$this->db->table(OrgMember::getTable())
					->where('name', $buddy['name'])
					->delete();
				$this->buddylistManager->remove($buddy['name'], 'org');
				unset($this->chatBot->guildmembers[$buddy['name']]);
			}
		}

		$this->chatBot->ready = true;
		$this->logger->notice('Finished Roster update');
	}
}
