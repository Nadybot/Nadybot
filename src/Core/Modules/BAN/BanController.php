<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use function Amp\async;

use AO\Package\Out\PrivateChannelKick;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Events\ConnectEvent;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	DBSchema\Audit,
	DBSchema\BanEntry,
	DBSchema\Player,
	EventManager,
	Events\Event,
	Exceptions\SQLException,
	ImporterInterface,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Text,
	Util,
};
use Nadybot\Modules\ORGLIST_MODULE\Organization;
use Nadybot\Modules\WHOIS_MODULE\NameHistory;
use Psr\Log\LoggerInterface;
use Throwable;

#[
	NCA\Instance,
	NCA\Importer(key: 'banlist', class: ExportedBan::class),
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'ban',
		accessLevel: 'mod',
		description: 'Ban a character from this bot',
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: 'banlist',
		accessLevel: 'mod',
		description: 'Shows who is on the banlist',
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: 'unban',
		accessLevel: 'mod',
		description: 'Unban a character from this bot',
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: 'orgban',
		accessLevel: 'mod',
		description: 'Ban or unban a whole org',
		alias: 'orgbans'
	),

	NCA\ProvidesEvent(
		event: SyncBanEvent::class,
		desc: 'Triggered whenever someone is banned'
	),
	NCA\ProvidesEvent(
		event: SyncBanDeleteEvent::class,
		desc: "Triggered when someone's ban is lifted"
	)
]
class BanController extends ModuleInstance implements ImporterInterface {
	/** Always ban all alts, not just 1 char */
	#[NCA\Setting\Boolean]
	public bool $banAllAlts = false;

	/** Notify character when banned from bot */
	#[NCA\Setting\Boolean]
	public bool $notifyBannedPlayer = true;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private GuildManager $guildManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	/**
	 * List of all banned players, indexed by UID
	 *
	 * @var array<int,BanEntry>
	 */
	private $banlist = [];

	/**
	 * List of all banned orgs, indexed by guild_id
	 *
	 * @var array<int,BannedOrg>
	 */
	private $orgbanlist = [];

	#[NCA\Event(
		name: ConnectEvent::EVENT_MASK,
		description: 'Upload banlist into memory',
		defaultStatus: 1
	)]
	public function initializeBanList(ConnectEvent $eventObj): void {
		$this->uploadBanlist();
		$this->uploadOrgBanlist();
	}

	/** Temporarily ban a player from this bot */
	#[NCA\HandlesCommand('ban')]
	#[NCA\Help\Group('ban')]
	#[NCA\Help\Example('<symbol>ban badplayer 2 weeks for ninjalooting')]
	public function banPlayerWithTimeAndReasonCommand(
		CmdContext $context,
		PCharacter $who,
		PDuration $duration,
		#[NCA\Str('for', 'reason')] string $for,
		string $reason
	): void {
		$who = $who();
		$length = $duration->toSecs();

		[$success, $msgs] = $this->banPlayer($who, $context->char->name, $length, $reason, $context);
		if (count($msgs)) {
			$context->reply(implode("\n", $msgs));
		}
		if (!$success) {
			return;
		}

		$timeString = Util::unixtimeToReadable($length);
		$context->reply("You have banned <highlight>{$who}<end> from this bot for {$timeString}.");
		if (!$this->notifyBannedPlayer) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been banned from this bot by <highlight>{$context->char->name}<end> for {$timeString}. ".
			"Reason: {$reason}",
			$who
		);
	}

	/** Temporarily ban a player from this bot, not giving any reason */
	#[NCA\HandlesCommand('ban')]
	#[NCA\Help\Group('ban')]
	#[NCA\Help\Example('<symbol>ban badplayer 2 weeks')]
	public function banPlayerWithTimeCommand(
		CmdContext $context,
		PCharacter $who,
		PDuration $duration
	): void {
		$who = $who();
		$length = $duration->toSecs();

		[$success, $msgs] = $this->banPlayer($who, $context->char->name, $length, '', $context);
		if (count($msgs)) {
			$context->reply(implode("\n", $msgs));
		}
		if (!$success) {
			return;
		}

		$timeString = Util::unixtimeToReadable($length);
		$context->reply("You have banned <highlight>{$who}<end> from this bot for {$timeString}.");
		if (!$this->notifyBannedPlayer) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been banned from this bot by <highlight>{$context->char->name}<end> for {$timeString}.",
			$who
		);
	}

	/** Permanently ban a player from this bot */
	#[NCA\HandlesCommand('ban')]
	#[NCA\Help\Group('ban')]
	#[NCA\Help\Example('<symbol>ban badplayer for ninjalooting')]
	public function banPlayerWithReasonCommand(
		CmdContext $context,
		PCharacter $who,
		#[NCA\Str('for', 'reason')] string $for,
		string $reason
	): void {
		$who = $who();

		[$success, $msgs] = $this->banPlayer($who, $context->char->name, null, $reason, $context);
		if (count($msgs)) {
			$context->reply(implode("\n", $msgs));
		}
		if (!$success) {
			return;
		}

		$context->reply("You have permanently banned <highlight>{$who}<end> from this bot.");
		if (!$this->notifyBannedPlayer) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been permanently banned from this bot by <highlight>{$context->char->name}<end>. ".
			"Reason: {$reason}",
			$who
		);
	}

	/** Permanently ban a player from this bot, without giving a reason */
	#[NCA\HandlesCommand('ban')]
	#[NCA\Help\Group('ban')]
	#[NCA\Help\Example('<symbol>ban badplayer')]
	public function banPlayerCommand(CmdContext $context, PCharacter $who): void {
		$who = $who();

		[$success, $msgs] = $this->banPlayer($who, $context->char->name, null, '', $context);
		if (count($msgs)) {
			$context->reply(implode("\n", $msgs));
		}
		if (!$success) {
			return;
		}

		$context->reply("You have permanently banned <highlight>{$who}<end> from this bot.");
		if (!$this->notifyBannedPlayer) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been permanently banned from this bot by <highlight>{$context->char->name}<end>.",
			$who
		);
	}

	/**
	 * List the current ban list, optionally searching for &lt;search&gt;
	 * The search supports ? and * as wildcards
	 */
	#[NCA\HandlesCommand('banlist')]
	#[NCA\Help\Group('ban')]
	#[NCA\Help\Example('<symbol>banlist nady*', 'to search for characters starting with nady')]
	#[NCA\Help\Example('<symbol>banlist *iut*', 'to search for characters containing iut')]
	public function banlistCommand(CmdContext $context, ?string $search): void {
		$banlist = $this->getBanlist();
		if (isset($search)) {
			$banlist = array_filter(
				$banlist,
				static function (BanEntry $entry) use ($search): bool {
					return isset($entry->name) && fnmatch($search, $entry->name, \FNM_CASEFOLD);
				}
			);
		}
		$count = count($banlist);
		if ($count === 0) {
			$msg = 'No one is currently banned from this bot.';
			if (isset($search)) {
				$msg = "No one matching <highlight>{$search}<end> is currently banned from this bot.";
			}
			$context->reply($msg);
			return;
		}
		$bans = [];
		foreach ($banlist as $ban) {
			if (!isset($ban->name)) {
				continue;
			}
			$blob = "<header2>{$ban->name}<end>\n";
			if (isset($ban->time)) {
				$blob .= '<tab>Date: <highlight>' . Util::date($ban->time) . "<end>\n";
			}
			$blob .= "<tab>By: <highlight>{$ban->admin}<end>\n";
			if (isset($ban->banend) && $ban->banend !== 0) {
				$blob .= '<tab>Ban ends: <highlight>' . Util::unixtimeToReadable($ban->banend - time(), false) . "<end>\n";
			} else {
				$blob .= "<tab>Ban ends: <highlight>Never<end>\n";
			}

			if (isset($ban->reason) && $ban->reason !== '') {
				$blob .= "<tab>Reason: <highlight>{$ban->reason}<end>\n";
			}
			$bans []= $blob;
		}
		$blob = implode("\n<pagebreak>", $bans);
		if (isset($search)) {
			$msg = $this->text->makeBlob("Banlist matches for '{$search}' ({$count})", $blob);
		} else {
			$msg = $this->text->makeBlob("Banlist ({$count})", $blob);
		}
		$context->reply($msg);
	}

	/** Unbans a character and all their alts from this bot */
	#[NCA\HandlesCommand('unban')]
	#[NCA\Help\Group('ban')]
	public function unbanAllCommand(
		CmdContext $context,
		#[NCA\Str('all')] string $all,
		PCharacter $who
	): void {
		$who = $who();

		$charId = $this->chatBot->getUid($who);
		if ($charId === null) {
			$context->reply("Player <highlight>{$who}<end> doesn't exist.");
			return;
		}
		if (!$this->isBanned($charId)) {
			$context->reply("<highlight>{$who}<end> is not banned on this bot.");
			return;
		}

		$altInfo = $this->altsController->getAltInfo($who);
		$toUnban = [$altInfo->main, ...$altInfo->getAllValidatedAlts()];
		foreach ($toUnban as $charName) {
			$charId = $this->chatBot->getUid($charName);
			if (!isset($charId) || !$this->isBanned($charId)) {
				continue;
			}
			$this->remove($charId);
			$event = new SyncBanDeleteEvent(
				uid: $charId,
				name: $charName,
				unbanned_by: $context->char->name,
				forceSync: $context->forceSync,
			);
			$this->eventManager->fireEvent($event);
		}

		$context->reply("You have unbanned <highlight>{$who}<end> and all their alts from this bot.");
		if ($this->notifyBannedPlayer) {
			$this->chatBot->sendMassTell("You have been unbanned from this bot by {$context->char->name}.", $who);
		}
	}

	/** Unbans a character from this bot */
	#[NCA\HandlesCommand('unban')]
	#[NCA\Help\Group('ban')]
	public function unbanCommand(CmdContext $context, PCharacter $who): void {
		$who = $who();

		$charId = $this->chatBot->getUid($who);
		if (!isset($charId)) {
			$context->reply("Player <highlight>{$who}<end> doesn't exist.");
			return;
		}
		if (!$this->isBanned($charId)) {
			$context->reply("<highlight>{$who}<end> is not banned on this bot.");
			return;
		}

		$this->remove($charId);

		$event = new SyncBanDeleteEvent(
			uid: $charId,
			name: $who,
			unbanned_by: $context->char->name,
			forceSync: $context->forceSync,
		);
		$this->eventManager->fireEvent($event);

		$context->reply("You have unbanned <highlight>{$who}<end> from this bot.");
		if ($this->notifyBannedPlayer) {
			$this->chatBot->sendMassTell("You have been unbanned from this bot by {$context->char->name}.", $who);
		}
	}

	#[NCA\Event(
		name: 'timer(1min)',
		description: 'Check temp bans to see if they have expired',
		defaultStatus: 1
	)]
	public function checkTempBan(Event $eventObj): void {
		$numRows = $this->db->table(BanEntry::getTable())
			->whereNotNull('banend')
			->where('banend', '!=', 0)
			->where('banend', '<', time())
			->delete();

		if ($numRows > 0) {
			$this->uploadBanlist();
		}

		$numRows = $this->db->table(BannedOrg::getTable())
			->whereNotNull('end')
			->where('end', '<', time())
			->delete();

		if ($numRows > 0) {
			$this->orgbanlist = array_filter(
				$this->orgbanlist,
				static function (BannedOrg $ban): bool {
					return !isset($ban->end) || $ban->end >= time();
				}
			);
		}
	}

	/**
	 * Actually add $charId to the banlist with optional duration and reason
	 *
	 * @param int         $charId The UID of the player to ban
	 * @param string      $sender The name of the player banning them
	 * @param null|int    $length length of the ban in s, or null/0 for unlimited
	 * @param null|string $reason Optional reason  for the ban
	 *
	 * @return bool true on success, false on failure
	 *
	 * @throws SQLException
	 */
	public function add(int $charId, string $sender, ?int $length, ?string $reason): bool {
		$banEnd = 0;
		if ($length !== null) {
			$banEnd = time() + $length;
		}

		$inserted = $this->db->insert(new BanEntry(
			charid: $charId,
			admin: $sender,
			time: time(),
			reason: $reason,
			banend: $banEnd,
		));
		$this->uploadBanlist();

		$charName = $this->chatBot->getName($charId);
		if (!is_string($charName)) {
			$charName = (string)$charId;
		}
		$audit = new Audit(
			actor: $sender,
			actee: $charName,
			action: $banEnd ? AccessManager::TEMP_BAN : AccessManager::PERM_BAN,
			value: $reason,
		);
		$this->accessManager->addAudit($audit);

		return $inserted === 1;
	}

	/** Remove $charId from the banlist */
	public function remove(int $charId): bool {
		$deleted = $this->db->table(BanEntry::getTable())
			->where('charid', $charId)
			->delete();

		if ($deleted === 0) {
			return false;
		}
		unset($this->banlist[$charId]);

		return true;
	}

	/** Sync the banlist from the database */
	public function uploadBanlist(): void {
		$this->banlist = [];

		$bans = $this->db->table(BanEntry::getTable())
			->orderBy('time')
			->asObj(BanEntry::class);

		$bannedUids = $bans->pluck('charid')->toArray();

		/** @var Collection<int,NameHistory> */
		$names = $this->db->table(BanEntry::getTable(), 'bl')
			->join(NameHistory::getTable(as: 'nh'), 'bl.charid', 'nh.charid')
			->where('nh.dimension', $this->db->getDim())
			->orderBy('nh.dt')
			->select('nh.*')
			->asObj(NameHistory::class)
			->keyBy('charid');

		/** @var Collection<int,Player> */
		$players = $this->playerManager
			->searchByUids($this->db->getDim(), ...$bannedUids)
			->keyBy('charid');
		$bans->each(function (BanEntry $ban) use ($players, $names): void {
			$ban->name = $players->get($ban->charid)?->name
				?? $this->chatBot->getName($ban->charid)
				?? $names->get($ban->charid)?->name
				?? (string)$ban->charid;
			$this->banlist[$ban->charid] = $ban;
		});
	}

	/** Sync the org-banlist from the database */
	public function uploadOrgBanlist(): void {
		$this->db->table(BannedOrg::getTable())
			->asObj(BannedOrg::class)
			->each(function (BannedOrg $ban): void {
				$this->addOrgToBanlist($ban);
			});
	}

	/** Check if $charId is banned */
	public function isBanned(int $charId): bool {
		return isset($this->banlist[$charId]);
	}

	public function isOnBanlist(int $charId): bool {
		if ($this->isBanned($charId)) {
			return true;
		}
		if (!count($this->orgbanlist)) {
			return false;
		}
		$name = $this->chatBot->getName($charId, true);
		if ($name === null) {
			return true;
		}
		$player = $this->playerManager->byName($name);
		if (!isset($player) || !isset($player->guild_id)) {
			return false;
		}

		return isset($this->orgbanlist[$player->guild_id]);
	}

	public function orgIsBanned(int $orgId): bool {
		return isset($this->orgbanlist[$orgId]);
	}

	/** @return array<int,BanEntry> */
	public function getBanlist(): array {
		return $this->banlist;
	}

	/** List all currently banned org */
	#[NCA\HandlesCommand('orgban')]
	#[NCA\Help\Group('ban')]
	public function orgbanListCommand(CmdContext $context): void {
		$blocks = [];
		foreach ($this->orgbanlist as $orgIf => $ban) {
			$blocks []= $this->renderBannedOrg($ban);
		}
		$count = count($blocks);
		if ($count === 0) {
			$context->reply('No orgs are banned at the moment');
			return;
		}
		$msg = $this->text->makeBlob(
			"Banned orgs ({$count})",
			implode("\n", $blocks)
		);
		$context->reply($msg);
	}

	public function renderBannedOrg(BannedOrg $ban): string {
		$unbanLink = Text::makeChatcmd('remove', "/tell <myname> orgban rem {$ban->org_id}");
		$blob = '<header2>' . ($ban->org_name ?? $ban->org_id) . "<end>\n".
			"<tab>Banned by: <highlight>{$ban->banned_by}<end> [{$unbanLink}]\n".
			'<tab>Ban starts: <highlight>' . Util::date($ban->start) . "<end>\n";
		if (isset($ban->end)) {
			$blob .= '<tab>Ban ends: <highlight>' . Util::date($ban->end) . "<end>\n";
		}
		$blob .= "<tab>Reason: <highlight>{$ban->reason}<end>";
		return $blob;
	}

	/** Ban a whole organization from the bot by their org id */
	#[NCA\HandlesCommand('orgban')]
	#[NCA\Help\Group('ban')]
	#[NCA\Help\Example(
		command: '<symbol>orgban add 725003 2w1d reason A bunch of oddballs',
		description: 'bans the org <i>Troet</i> for 2 weeks and 1 day from the bot'
	)]
	#[NCA\Help\Epilogue(
		"Use <a href='chatcmd:///tell <myname> help findorg'><symbol>findorg</a> to find an org's ID.\n".
		"See <a href='chatcmd:///tell <myname> help budatime'><symbol>help budatime</a> for info on the format of the &lt;duration&gt; parameter.\n"
	)]
	public function orgbanAddByIdCommand(
		CmdContext $context,
		#[NCA\Str('add')] string $add,
		int $orgId,
		?PDuration $duration,
		#[NCA\Str('for', 'reason', 'because')] string $for,
		string $reason
	): void {
		try {
			$msg = $this->banOrg($orgId, $duration ? $duration() : null, $context->char->name, $reason);
			$msg ??= 'The system is currently not ready to process bans.';
			$context->reply($msg);
		} catch (Throwable $e) {
			$context->reply($e->getMessage());
		}
	}

	/** Ban a whole organization from the bot by their org id */
	#[NCA\HandlesCommand('orgban')]
	#[NCA\Help\Group('ban')]
	#[NCA\Help\Example(
		command: '<symbol>orgban add 725003',
		description: 'bans the org <i>Troet</i> permanently from the bot'
	)]
	public function orgbanAddByIdWithoutReasonCommand(
		CmdContext $context,
		#[NCA\Str('add')] string $add,
		int $orgId,
		?PDuration $duration,
	): void {
		try {
			$msg = $this->banOrg($orgId, $duration ? $duration() : null, $context->char->name, 'No reason given');
			$msg ??= 'The system is currently not ready to process bans.';
			$context->reply($msg);
		} catch (Throwable $e) {
			$context->reply($e->getMessage());
		}
	}

	/** @param iterable<Organization> $orgs */
	public function formatOrgsToBan(iterable $orgs, ?string $duration, string $reason): string {
		$blob = '';
		$banCmd = "/tell <myname> orgban add %d reason {$reason}";
		if (isset($duration)) {
			$banCmd = "/tell <myname> orgban add %d {$duration} reason {$reason}";
		}
		foreach ($orgs as $org) {
			$addLink = Text::makeChatcmd('ban', sprintf($banCmd, $org->id));
			$blob .= $org->faction->inColor($org->name) . " ({$org->id}) - {$org->num_members} members [{$addLink}]\n\n";
		}
		return $blob;
	}

	public function banOrg(int $orgId, ?string $duration, string $bannedBy, string $reason): ?string {
		if ($this->orgIsBanned($orgId)) {
			return '<highlight>' . $this->orgbanlist[$orgId]->org_name.
				'<end> is already banned.';
		}
		$endDate = null;
		if (isset($duration)) {
			$durationInSecs = Util::parseTime($duration);
			if ($durationInSecs === 0) {
				return "<highlight>{$duration}<end> is not a valid duration.";
			}
			$endDate = time() + $durationInSecs;
		}
		$ban = new BannedOrg(
			org_id: $orgId,
			banned_by: $bannedBy,
			start: time(),
			end: $endDate,
			reason: $reason,
			org_name: "org #{$orgId}",
		);
		$this->db->insert($ban);
		return $this->addOrgToBanlist($ban);
	}

	/** Remove an organization from the ban list, given their org id */
	#[NCA\HandlesCommand('orgban')]
	#[NCA\Help\Group('ban')]
	public function orgbanRemCommand(CmdContext $context, PRemove $rem, int $orgId): void {
		if (!$this->orgIsBanned($orgId)) {
			$guild = $this->guildManager->byId($orgId);
			if (!isset($guild)) {
				$context->reply("<highlight>{$orgId}<end> is not a valid org id.");
				return;
			}
			$context->reply($guild->getColorName() . ' is currently not banned.');
			return;
		}
		$ban = $this->orgbanlist[$orgId];
		$this->db->table(BannedOrg::getTable())
			->where('org_id', $orgId)
			->delete();
		$context->reply("Removed <highlight>{$ban->org_name}<end> from the banlist.");
		unset($this->orgbanlist[$orgId]);
	}

	#[NCA\Event(
		name: SyncBanEvent::EVENT_MASK,
		description: 'Sync external bans'
	)]
	public function processBanSyncEvent(SyncBanEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->add($event->uid, $event->banned_by, $event->banned_until, $event->reason);
	}

	#[NCA\Event(
		name: SyncBanDeleteEvent::EVENT_MASK,
		description: 'Sync external ban lifts'
	)]
	public function processBanDeleteSyncEvent(SyncBanDeleteEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->remove($event->uid);
	}

	/** @param list<object> $data A list of all mains and their alts */
	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$numImported = 0;
		$logger->notice('Importing {num_bans} ban(s)', [
			'num_bans' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all bans');
			$db->table(BanEntry::getTable())->truncate();
			foreach ($data as $ban) {
				if (!($ban instanceof ExportedBan)) {
					throw new InvalidArgumentException('BanController::import() was called with wrong banlist format');
				}

				/** @psalm-suppress PossiblyNullArgument */
				$id = $ban->character->id ?? $this->chatBot->getUid($ban->character->name);
				if (!isset($id)) {
					continue;
				}
				$db->insert(new BanEntry(
					charid: $id,
					admin: $ban->bannedBy?->tryGetName() ?? $this->config->main->character,
					time: $ban->banStart ?? time(),
					reason: $ban->banReason ?? 'None given',
					banend: $ban->banEnd ?? 0,
				));
				$numImported++;
			}
		} catch (Throwable $e) {
			$logger->error('{error}. Rolling back changes.', [
				'error' => rtrim($e->getMessage(), '.'),
				'exception' => $e,
			]);
			$db->rollback();
			return;
		}
		$db->commit();
		$this->uploadBanlist();
		$this->logger->notice('{num_imported} bans successfully imported', [
			'num_imported' => $numImported,
		]);
	}

	protected function addOrgToBanlist(BannedOrg $ban): ?string {
		$this->orgbanlist[$ban->org_id] = $ban;
		if (!$this->chatBot->ready) {
			return null;
		}

		$guild = $this->guildManager->byId($ban->org_id);

		$ban->org_name = (string)$ban->org_id;
		if (isset($guild)) {
			$ban->org_name = $guild->orgname;
		}
		if (!isset($this->orgbanlist[$ban->org_id])) {
			return "Not adding <highlight>{$ban->org_name}<end> to the banlist, ".
				'because they were unbanned before we finished looking up data.';
		}
		$this->orgbanlist[$ban->org_id] = $ban;
		// Kick all org members from our private chat
		if (isset($guild)) {
			foreach ($guild->members as $name => $char) {
				if ($this->chatBot->chatlist[$char->name]) {
					$this->logger->notice('Kicking banned char {name} from private channel', [
						'name' => $char->name,
					]);
					$package = new PrivateChannelKick(charId: $char->charid);
					$this->chatBot->sendPackage($package);
				}
			}
		}
		return "Added <highlight>{$ban->org_name}<end> to the banlist.";
	}

	/**
	 * This helper method bans player with given arguments.
	 *
	 * @return array{bool,list<string>}
	 */
	private function banPlayer(string $who, string $sender, ?int $length, ?string $reason, CmdContext $context): array {
		$toBan = [$who];
		if ($this->banAllAlts) {
			$altInfo = $this->altsController->getAltInfo($who);
			$toBan = [$altInfo->main, ...$altInfo->getAllValidatedAlts()];
		}
		$numSuccess = 0;
		$numErrors = 0;
		$msgs = [];
		foreach ($toBan as $charName) {
			$charId = $this->chatBot->getUid($charName);
			if (!isset($charId)) {
				$msgs []= "Character <highlight>{$charName}<end> does not exist.";
				$numErrors++;
				continue;
			}

			if ($this->isBanned($charId)) {
				$msgs []= "Character <highlight>{$charName}<end> is already banned.";
				$numErrors++;
				continue;
			}

			if ($this->accessManager->compareCharacterAccessLevels($sender, $charName) <= 0) {
				$msgs []= "You must have an access level higher than <highlight>{$charName}<end> ".
					'to perform this action.';
				$numErrors++;
				continue;
			}

			if ($length === 0) {
				return [false, $msgs];
			}

			if ($this->add($charId, $sender, $length, $reason)) {
				$package = new PrivateChannelKick(charId: $charId);
				$this->chatBot->sendPackage($package);
				$audit = new Audit(
					actor: $sender,
					actee: $charName,
					action: AccessManager::KICK,
					value: 'banned',
				);
				$this->accessManager->addAudit($audit);
				$numSuccess++;

				$event = new SyncBanEvent(
					uid: $charId,
					name: $charName,
					banned_by: $sender,
					banned_until: $length,
					reason: $reason,
					forceSync: $context->forceSync,
				);
				$this->eventManager->fireEvent($event);
				async($this->playerManager->byName(...), $charName)->ignore();
			} else {
				$numErrors++;
			}
		}
		return [$numSuccess > 0, $msgs];
	}
}
