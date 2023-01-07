<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use function Amp\Promise\rethrow;
use function Amp\{asyncCall, call};

use Amp\{Promise, Success};
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	DBSchema\Audit,
	DBSchema\BanEntry,
	DBSchema\Player,
	Event,
	EventManager,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PDuration,
	ParamClass\PRemove,
	SQLException,
	Text,
	Util,
};
use Nadybot\Modules\WHOIS_MODULE\NameHistory;
use Throwable;

#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "ban",
		accessLevel: "mod",
		description: "Ban a character from this bot",
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: "banlist",
		accessLevel: "mod",
		description: "Shows who is on the banlist",
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: "unban",
		accessLevel: "mod",
		description: "Unban a character from this bot",
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: "orgban",
		accessLevel: "mod",
		description: "Ban or unban a whole org",
		alias: "orgbans"
	),

	NCA\ProvidesEvent(
		event: "sync(ban)",
		desc: "Triggered whenever someone is banned"
	),
	NCA\ProvidesEvent(
		event: "sync(ban-delete)",
		desc: "Triggered when someone's ban is lifted"
	)
]
class BanController extends ModuleInstance {
	public const DB_TABLE = "banlist_<myname>";
	public const DB_TABLE_BANNED_ORGS = "banned_orgs_<myname>";

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	/** Always ban all alts, not just 1 char */
	#[NCA\Setting\Boolean]
	public bool $banAllAlts = false;

	/** Notify character when banned from bot */
	#[NCA\Setting\Boolean]
	public bool $notifyBannedPlayer = true;

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

	#[NCA\Setup]
	public function setup(): void {
		if ($this->db->schema()->hasTable("players")) {
			$this->uploadBanlist();
		}

		$this->uploadOrgBanlist();
	}

	#[NCA\Event(
		name: "connect",
		description: "Upload banlist into memory",
		defaultStatus: 1
	)]
	public function initializeBanList(Event $eventObj): void {
		$this->uploadBanlist();
		$this->uploadOrgBanlist();
	}

	/** Temporarily ban a player from this bot */
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer 2 weeks for ninjalooting")]
	public function banPlayerWithTimeAndReasonCommand(
		CmdContext $context,
		PCharacter $who,
		PDuration $duration,
		#[NCA\Str("for", "reason")] string $for,
		string $reason
	): Generator {
		$who = $who();
		$length = $duration->toSecs();

		[$success, $msgs] = yield $this->banPlayer($who, $context->char->name, $length, $reason, $context);
		if (isset($msgs) && count($msgs)) {
			$context->reply(join("\n", $msgs));
		}
		if (!$success) {
			return;
		}

		$timeString = $this->util->unixtimeToReadable($length);
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
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer 2 weeks")]
	public function banPlayerWithTimeCommand(
		CmdContext $context,
		PCharacter $who,
		PDuration $duration
	): Generator {
		$who = $who();
		$length = $duration->toSecs();

		[$success, $msgs] = yield $this->banPlayer($who, $context->char->name, $length, '', $context);
		if (isset($msgs) && count($msgs)) {
			$context->reply(join("\n", $msgs));
		}
		if (!$success) {
			return;
		}

		$timeString = $this->util->unixtimeToReadable($length);
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
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer for ninjalooting")]
	public function banPlayerWithReasonCommand(
		CmdContext $context,
		PCharacter $who,
		#[NCA\Str("for", "reason")] string $for,
		string $reason
	): Generator {
		$who = $who();

		[$success, $msgs] = yield $this->banPlayer($who, $context->char->name, null, $reason, $context);
		if (isset($msgs) && count($msgs)) {
			$context->reply(join("\n", $msgs));
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
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer")]
	public function banPlayerCommand(CmdContext $context, PCharacter $who): Generator {
		$who = $who();

		[$success, $msgs] = yield $this->banPlayer($who, $context->char->name, null, '', $context);
		if (isset($msgs) && count($msgs)) {
			$context->reply(join("\n", $msgs));
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
	#[NCA\HandlesCommand("banlist")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>banlist nady*", "to search for characters starting with nady")]
	#[NCA\Help\Example("<symbol>banlist *iut*", "to search for characters containing iut")]
	public function banlistCommand(CmdContext $context, ?string $search): void {
		$banlist = $this->getBanlist();
		if (isset($search)) {
			$banlist = array_filter(
				$banlist,
				function (BanEntry $entry) use ($search): bool {
					return fnmatch($search, $entry->name, FNM_CASEFOLD);
				}
			);
		}
		$count = count($banlist);
		if ($count === 0) {
			$msg = "No one is currently banned from this bot.";
			if (isset($search)) {
				$msg = "No one matching <highlight>{$search}<end> is currently banned from this bot.";
			}
			$context->reply($msg);
			return;
		}
		$bans = [];
		foreach ($banlist as $ban) {
			$blob = "<header2>{$ban->name}<end>\n";
			if (isset($ban->time)) {
				$blob .= "<tab>Date: <highlight>" . $this->util->date($ban->time) . "<end>\n";
			}
			$blob .= "<tab>By: <highlight>{$ban->admin}<end>\n";
			if (isset($ban->banend) && $ban->banend !== 0) {
				$blob .= "<tab>Ban ends: <highlight>" . $this->util->unixtimeToReadable($ban->banend - time(), false) . "<end>\n";
			} else {
				$blob .= "<tab>Ban ends: <highlight>Never<end>\n";
			}

			if (isset($ban->reason) && $ban->reason !== '') {
				$blob .= "<tab>Reason: <highlight>{$ban->reason}<end>\n";
			}
			$bans []= $blob;
		}
		$blob = join("\n<pagebreak>", $bans);
		if (isset($search)) {
			$msg = $this->text->makeBlob("Banlist matches for '{$search}' ({$count})", $blob);
		} else {
			$msg = $this->text->makeBlob("Banlist ({$count})", $blob);
		}
		$context->reply($msg);
	}

	/** Unbans a character and all their alts from this bot */
	#[NCA\HandlesCommand("unban")]
	#[NCA\Help\Group("ban")]
	public function unbanAllCommand(
		CmdContext $context,
		#[NCA\Str("all")] string $all,
		PCharacter $who
	): Generator {
		$who = $who();

		$charId = yield $this->chatBot->getUid2($who);
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
			$charId = yield $this->chatBot->getUid2($charName);
			if (!isset($charId) || !$this->isBanned($charId)) {
				continue;
			}
			$this->remove($charId);
			$event = new SyncBanDeleteEvent();
			$event->uid = $charId;
			$event->name = $charName;
			$event->unbanned_by = $context->char->name;
			$event->forceSync = $context->forceSync;
			$this->eventManager->fireEvent($event);
		}

		$context->reply("You have unbanned <highlight>{$who}<end> and all their alts from this bot.");
		if ($this->notifyBannedPlayer) {
			$this->chatBot->sendMassTell("You have been unbanned from this bot by {$context->char->name}.", $who);
		}
	}

	/** Unbans a character from this bot */
	#[NCA\HandlesCommand("unban")]
	#[NCA\Help\Group("ban")]
	public function unbanCommand(CmdContext $context, PCharacter $who): Generator {
		$who = $who();

		$charId = yield $this->chatBot->getUid2($who);
		if (!$charId) {
			$context->reply("Player <highlight>{$who}<end> doesn't exist.");
			return;
		}
		if (!$this->isBanned($charId)) {
			$context->reply("<highlight>{$who}<end> is not banned on this bot.");
			return;
		}

		$this->remove($charId);

		$event = new SyncBanDeleteEvent();
		$event->uid = $charId;
		$event->name = $who;
		$event->unbanned_by = $context->char->name;
		$event->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($event);

		$context->reply("You have unbanned <highlight>{$who}<end> from this bot.");
		if ($this->notifyBannedPlayer) {
			$this->chatBot->sendMassTell("You have been unbanned from this bot by {$context->char->name}.", $who);
		}
	}

	#[NCA\Event(
		name: "timer(1min)",
		description: "Check temp bans to see if they have expired",
		defaultStatus: 1
	)]
	public function checkTempBan(Event $eventObj): void {
		$numRows = $this->db->table(self::DB_TABLE)
			->whereNotNull("banend")
			->where("banend", "!=", 0)
			->where("banend", "<", time())
			->delete();

		if ($numRows > 0) {
			$this->uploadBanlist();
		}

		$numRows = $this->db->table(self::DB_TABLE_BANNED_ORGS)
			->whereNotNull("end")
			->where("end", "<", time())
			->delete();

		if ($numRows > 0) {
			$this->orgbanlist = array_filter(
				$this->orgbanlist,
				function (BannedOrg $ban): bool {
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

		$inserted = $this->db->table(self::DB_TABLE)
			->insert([
				"charid" => $charId,
				"admin" => $sender,
				"time" => time(),
				"reason" => $reason,
				"banend" => $banEnd,
			]);

		$this->uploadBanlist();

		$audit = new Audit();
		$audit->actor = $sender;
		$charName = $this->chatBot->lookup_user($charId);
		if (!is_string($charName)) {
			$charName = (string)$charId;
		}
		$audit->actee = $charName;
		$audit->action = $banEnd ? AccessManager::TEMP_BAN : AccessManager::PERM_BAN;
		$audit->value = $reason;
		$this->accessManager->addAudit($audit);

		return $inserted;
	}

	/** Remove $charId from the banlist */
	public function remove(int $charId): bool {
		$deleted = $this->db->table(self::DB_TABLE)
			->where("charid", $charId)
			->delete();

		$this->uploadBanlist();

		return $deleted >= 1;
	}

	/** Sync the banlist from the database */
	public function uploadBanlist(): void {
		$this->banlist = [];

		$bans = $this->db->table(self::DB_TABLE)
			->orderBy("time")
			->asObj(BanEntry::class);

		/** @var Collection<int,NameHistory> */
		$names = $this->db->table("name_history")
			->where("dimension", $this->db->getDim())
			->orderBy("dt")
			->asObj(NameHistory::class)
			->keyBy("charid");
		$bannedUids = $bans->pluck("charid")->toArray();

		/** @var Collection<int,Player> */
		$players = $this->playerManager
			->searchByUids($this->db->getDim(), ...$bannedUids)
			->keyBy("charid");
		$bans->each(function (BanEntry $ban) use ($players, $names): void {
			$ban->name = $players->get($ban->charid)?->name
				?? $this->chatBot->id[$ban->charid]
				?? $names->get($ban->charid)?->name
				?? (string)$ban->charid;
			$this->banlist[$ban->charid] = $ban;
		});
	}

	/** Sync the org-banlist from the database */
	public function uploadOrgBanlist(): void {
		$this->db->table(self::DB_TABLE_BANNED_ORGS)
			->asObj(BannedOrg::class)
			->each(function (BannedOrg $ban): void {
				$this->addOrgToBanlist($ban);
			});
	}

	/** Check if $charId is banned */
	public function isBanned(int $charId): bool {
		return isset($this->banlist[$charId]);
	}

	/** @return Promise<bool> */
	public function isOnBanlist(int $charId): Promise {
		if ($this->isBanned($charId)) {
			return new Success(true);
		}
		if (empty($this->orgbanlist)) {
			return new Success(false);
		}
		if (!isset($this->chatBot->id[$charId])) {
			return new Success(true);
		}
		$name = (string)$this->chatBot->id[$charId];
		return call(function () use ($name): Generator {
			/** @var ?Player */
			$player = yield $this->playerManager->byName($name);
			if (!isset($player) || !isset($player->guild_id)) {
				return false;
			}

			return isset($this->orgbanlist[$player->guild_id]);
		});
	}

	/**
	 * Call either the notbanned or the banned callback for $charId
	 *
	 * @psalm-param null|callable(int, mixed...) $notBanned
	 * @psalm-param null|callable(int, mixed...) $banned
	 *
	 * @deprecated 6.1.0
	 */
	public function handleBan(int $charId, ?callable $notBanned, ?callable $banned, mixed ...$args): void {
		$notBanned ??= fn (int $charId, mixed ...$args): mixed => null;
		$banned ??= fn (int $charId, mixed ...$args): mixed => null;
		if (isset($this->banlist[$charId])) {
			$banned($charId, ...$args);
			return;
		}
		if (empty($this->orgbanlist)) {
			$notBanned($charId, ...$args);
			return;
		}
		if (!isset($this->chatBot->id[$charId])) {
			$banned($charId, ...$args);
			return;
		}
		$player = (string)$this->chatBot->id[$charId];
		asyncCall(function () use ($player, $banned, $charId, $notBanned, $args): Generator {
			$whois = yield $this->playerManager->byName($player);
			if (!isset($whois) || !isset($whois->guild_id)) {
				$notBanned($charId, ...$args);
				return;
			}

			if (isset($this->orgbanlist[$whois->guild_id])) {
				$banned($charId, ...$args);
				return;
			}
			$notBanned($charId, ...$args);
		});
	}

	public function orgIsBanned(int $orgId): bool {
		return isset($this->orgbanlist[$orgId]);
	}

	/** @return array<int,BanEntry> */
	public function getBanlist(): array {
		return $this->banlist;
	}

	/** List all currently banned org */
	#[NCA\HandlesCommand("orgban")]
	#[NCA\Help\Group("ban")]
	public function orgbanListCommand(CmdContext $context): void {
		$blocks = [];
		foreach ($this->orgbanlist as $orgIf => $ban) {
			$blocks []= $this->renderBannedOrg($ban);
		}
		$count = count($blocks);
		if ($count === 0) {
			$context->reply("No orgs are banned at the moment");
			return;
		}
		$msg = $this->text->makeBlob(
			"Banned orgs ({$count})",
			join("\n", $blocks)
		);
		$context->reply($msg);
	}

	public function renderBannedOrg(BannedOrg $ban): string {
		$unbanLink = $this->text->makeChatcmd("remove", "/tell <myname> orgban rem {$ban->org_id}");
		$blob = "<header2>" . ($ban->org_name ?? $ban->org_id) . "<end>\n".
			"<tab>Banned by: <highlight>{$ban->banned_by}<end> [{$unbanLink}]\n".
			"<tab>Ban starts: <highlight>" . $this->util->date($ban->start) . "<end>\n";
		if (isset($ban->end)) {
			$blob .= "<tab>Ban ends: <highlight>" . $this->util->date($ban->end) . "<end>\n";
		}
		$blob .= "<tab>Reason: <highlight>{$ban->reason}<end>";
		return $blob;
	}

	/** Ban a whole organization from the bot by their org id */
	#[NCA\HandlesCommand("orgban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example(
		command: "<symbol>orgban add 725003 2w1d reason A bunch of oddballs",
		description: "bans the org <i>Troet</i> for 2 weeks and 1 day from the bot"
	)]
	#[NCA\Help\Epilogue(
		"Use <a href='chatcmd:///tell <myname> help findorg'><symbol>findorg</a> to find an org's ID.\n".
		"See <a href='chatcmd:///tell <myname> help budatime'><symbol>help budatime</a> for info on the format of the &lt;duration&gt; parameter.\n"
	)]
	public function orgbanAddByIdCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $add,
		int $orgId,
		?PDuration $duration,
		#[NCA\Str("for", "reason", "because")] string $for,
		string $reason
	): Generator {
		try {
			$msg = yield $this->banOrg($orgId, $duration ? $duration() : null, $context->char->name, $reason);
			$msg ??= "The system is currently not ready to process bans.";
			$context->reply($msg);
		} catch (Throwable $e) {
			$context->reply($e->getMessage());
		}
	}

	/** Ban a whole organization from the bot by their org id */
	#[NCA\HandlesCommand("orgban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example(
		command: "<symbol>orgban add 725003",
		description: "bans the org <i>Troet</i> permanently from the bot"
	)]
	public function orgbanAddByIdWithoutReasonCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $add,
		int $orgId,
		?PDuration $duration,
	): Generator {
		try {
			$msg = yield $this->banOrg($orgId, $duration ? $duration() : null, $context->char->name, "No reason given");
			$msg ??= "The system is currently not ready to process bans.";
			$context->reply($msg);
		} catch (Throwable $e) {
			$context->reply($e->getMessage());
		}
	}

	/** @param \Nadybot\Modules\ORGLIST_MODULE\Organization[] $orgs */
	public function formatOrgsToBan(array $orgs, ?string $duration, string $reason): string {
		$blob = '';
		$banCmd = "/tell <myname> orgban add %d reason {$reason}";
		if (isset($duration)) {
			$banCmd = "/tell <myname> orgban add %d {$duration} reason {$reason}";
		}
		foreach ($orgs as $org) {
			$addLink = $this->text->makeChatcmd('ban', sprintf($banCmd, $org->id));
			$blob .= "<{$org->faction}>{$org->name}<end> ({$org->id}) - {$org->num_members} members [{$addLink}]\n\n";
		}
		return $blob;
	}

	/** @return Promise<?string> */
	public function banOrg(int $orgId, ?string $duration, string $bannedBy, string $reason): Promise {
		return call(function () use ($orgId, $duration, $bannedBy, $reason): Generator {
			if ($this->orgIsBanned($orgId)) {
				return "<highlight>" . $this->orgbanlist[$orgId]->org_name.
					"<end> is already banned.";
			}
			$endDate = null;
			if (isset($duration)) {
				$durationInSecs = $this->util->parseTime($duration);
				if ($durationInSecs === 0) {
					return "<highlight>{$duration}<end> is not a valid duration.";
				}
				$endDate = time() + $durationInSecs;
			}
			$ban = new BannedOrg();
			$ban->org_id = $orgId;
			$ban->banned_by = $bannedBy;
			$ban->start = time();
			$ban->end = $endDate;
			$ban->reason = $reason;
			$ban->org_name = "org #{$ban->org_id}";
			$this->db->insert(self::DB_TABLE_BANNED_ORGS, $ban, null);
			return yield $this->addOrgToBanlist($ban);
		});
	}

	/** Remove an organization from the ban list, given their org id */
	#[NCA\HandlesCommand("orgban")]
	#[NCA\Help\Group("ban")]
	public function orgbanRemCommand(CmdContext $context, PRemove $rem, int $orgId): Generator {
		if (!$this->orgIsBanned($orgId)) {
			/** @var ?Guild */
			$guild = yield $this->guildManager->byId($orgId);
			if (!isset($guild)) {
				$context->reply("<highlight>{$orgId}<end> is not a valid org id.");
				return null;
			}
			$context->reply($guild->getColorName() . " is currently not banned.");
			return null;
		}
		$ban = $this->orgbanlist[$orgId];
		$this->db->table(self::DB_TABLE_BANNED_ORGS)
			->where("org_id", $orgId)
			->delete();
		$context->reply("Removed <highlight>{$ban->org_name}<end> from the banlist.");
		unset($this->orgbanlist[$orgId]);
		return null;
	}

	#[NCA\Event(
		name: "sync(ban)",
		description: "Sync external bans"
	)]
	public function processBanSyncEvent(SyncBanEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->add($event->uid, $event->banned_by, $event->banned_until, $event->reason);
	}

	#[NCA\Event(
		name: "sync(ban-delete)",
		description: "Sync external ban lifts"
	)]
	public function processBanDeleteSyncEvent(SyncBanDeleteEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->remove($event->uid);
	}

	/** @return Promise<?string> */
	protected function addOrgToBanlist(BannedOrg $ban): Promise {
		return call(function () use ($ban): Generator {
			$this->orgbanlist[$ban->org_id] = $ban;
			if (!$this->chatBot->ready) {
				return null;
			}
			$guild = yield $this->guildManager->byId($ban->org_id);

			$ban->org_name = (string)$ban->org_id;
			if (isset($guild)) {
				$ban->org_name = $guild->orgname;
			}
			if (!isset($this->orgbanlist[$ban->org_id])) {
				return "Not adding <highlight>{$ban->org_name}<end> to the banlist, ".
					"because they were unbanned before we finished looking up data.";
			}
			$this->orgbanlist[$ban->org_id] = $ban;
			return "Added <highlight>{$ban->org_name}<end> to the banlist.";
		});
	}

	/**
	 * This helper method bans player with given arguments.
	 *
	 * @return Promise<array{bool,string[]}>
	 */
	private function banPlayer(string $who, string $sender, ?int $length, ?string $reason, CmdContext $context): Promise {
		return call(function () use ($who, $sender, $length, $reason, $context): Generator {
			$toBan = [$who];
			if ($this->banAllAlts) {
				$altInfo = $this->altsController->getAltInfo($who);
				$toBan = [$altInfo->main, ...$altInfo->getAllValidatedAlts()];
			}
			$numSuccess = 0;
			$numErrors = 0;
			$msgs = [];
			foreach ($toBan as $who) {
				$charId = yield $this->chatBot->getUid2($who);
				if (!$charId) {
					$msgs []= "Character <highlight>{$who}<end> does not exist.";
					$numErrors++;
					continue;
				}

				if ($this->isBanned($charId)) {
					$msgs []= "Character <highlight>{$who}<end> is already banned.";
					$numErrors++;
					continue;
				}

				if ($this->accessManager->compareCharacterAccessLevels($sender, $who) <= 0) {
					$msgs []= "You must have an access level higher than <highlight>{$who}<end> ".
						"to perform this action.";
					$numErrors++;
					continue;
				}

				if ($length === 0) {
					return [false, $msgs];
				}

				if ($this->add($charId, $sender, $length, $reason)) {
					$this->chatBot->privategroup_kick($who);
					$audit = new Audit();
					$audit->actor = $sender;
					$audit->actee = $who;
					$audit->action = AccessManager::KICK;
					$audit->value = "banned";
					$this->accessManager->addAudit($audit);
					$numSuccess++;

					$event = new SyncBanEvent();
					$event->uid = $charId;
					$event->name = $who;
					$event->banned_by = $sender;
					$event->banned_until = $length;
					$event->reason = $reason;
					$event->forceSync = $context->forceSync;
					$this->eventManager->fireEvent($event);
					rethrow($this->playerManager->byName($who));
				} else {
					$numErrors++;
				}
			}
			return [$numSuccess > 0, $msgs];
		});
	}
}
