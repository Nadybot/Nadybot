<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\{
	Attributes as NCA,
	Event,
	AccessManager,
	CmdContext,
	CommandReply,
	DBSchema\Audit,
	DBSchema\Player,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Util,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PDuration,
	ParamClass\PRemove,
	SettingManager,
	Text,
	DB,
	DBSchema\BanEntry,
	SQLException,
};

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

	NCA\Setting\Boolean(
		name: "ban_all_alts",
		description: "Always ban all alts, not just 1 char",
		defaultValue: false,
	),
]
class BanController extends ModuleInstance {
	public const DB_TABLE = "banlist_<myname>";
	public const DB_TABLE_BANNED_ORGS = "banned_orgs_<myname>";

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	/**
	 * List of all banned players, indexed by UID
	 * @var array<int,BanEntry>
	 */
	private $banlist = [];

	/**
	 * List of all banned orgs, indexed by guild_id
	 * @var array<int,BannedOrg>
	 */
	private $orgbanlist = [];

	#[NCA\Setting\Boolean(description: "Notify character when banned from bot")]
	public bool $notifyBannedPlayer = true;

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

	/**
	 * Temporarily ban a player from this bot
	 */
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer 2 weeks for ninjalooting")]
	public function banPlayerWithTimeAndReasonCommand(
		CmdContext $context,
		PCharacter $who,
		PDuration $duration,
		#[NCA\Str("for", "reason")] string $for,
		string $reason
	): void {
		$who = $who();
		$length = $duration->toSecs();

		if (!$this->banPlayer($who, $context->char->name, $length, $reason, $context)) {
			return;
		}

		$timeString = $this->util->unixtimeToReadable($length);
		$context->reply("You have banned <highlight>{$who}<end> from this bot for {$timeString}.");
		if (!$this->notifyBannedPlayer) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been banned from this bot by <highlight>{$context->char->name}<end> for {$timeString}. ".
			"Reason: $reason",
			$who
		);
	}

	/**
	 * Temporarily ban a player from this bot, not giving any reason
	 */
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer 2 weeks")]
	public function banPlayerWithTimeCommand(CmdContext $context, PCharacter $who, PDuration $duration): void {
		$who = $who();
		$length = $duration->toSecs();

		if (!$this->banPlayer($who, $context->char->name, $length, '', $context)) {
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

	/**
	 * Permanently ban a player from this bot
	 */
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer for ninjalooting")]
	public function banPlayerWithReasonCommand(
		CmdContext $context,
		PCharacter $who,
		#[NCA\Str("for", "reason")] string $for,
		string $reason
	): void {
		$who = $who();

		if (!$this->banPlayer($who, $context->char->name, null, $reason, $context)) {
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

	/**
	 * Permanently ban a player from this bot, without giving a reason
	 */
	#[NCA\HandlesCommand("ban")]
	#[NCA\Help\Group("ban")]
	#[NCA\Help\Example("<symbol>ban badplayer")]
	public function banPlayerCommand(CmdContext $context, PCharacter $who): void {
		$who = $who();

		if (!$this->banPlayer($who, $context->char->name, null, '', $context)) {
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
	 * List the current ban list
	 */
	#[NCA\HandlesCommand("banlist")]
	#[NCA\Help\Group("ban")]
	public function banlistCommand(CmdContext $context): void {
		$banlist = $this->getBanlist();
		$count = count($banlist);
		if ($count === 0) {
			$msg = "No one is currently banned from this bot.";
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
		$msg = $this->text->makeBlob("Banlist ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * Unbans a character and all their alts from this bot
	 */
	#[NCA\HandlesCommand("unban")]
	#[NCA\Help\Group("ban")]
	public function unbanAllCommand(CmdContext $context, #[NCA\Str("all")] string $all, PCharacter $who): void {
		$who = $who();

		$charId = $this->chatBot->get_uid($who);
		if (!$charId) {
			$context->reply("Player <highlight>{$who}<end> doesn't exist.");
			return;
		}
		if (!$this->isBanned($charId)) {
			$context->reply("<highlight>$who<end> is not banned on this bot.");
			return;
		}

		$altInfo = $this->altsController->getAltInfo($who);
		$toUnban = [$altInfo->main, ...$altInfo->getAllValidatedAlts()];
		foreach ($toUnban as $charName) {
			$charId = $this->chatBot->get_uid($charName);
			if (!$charId || !$this->isBanned($charId)) {
				continue;
			}
			$this->remove($charId);
		}

		$context->reply("You have unbanned <highlight>{$who}<end> and all their alts from this bot.");
		if ($this->notifyBannedPlayer) {
			$this->chatBot->sendMassTell("You have been unbanned from this bot by {$context->char->name}.", $who);
		}
	}

	/**
	 * Unbans a character from this bot
	 */
	#[NCA\HandlesCommand("unban")]
	#[NCA\Help\Group("ban")]
	public function unbanCommand(CmdContext $context, PCharacter $who): void {
		$who = $who();

		$charId = $this->chatBot->get_uid($who);
		if (!$charId) {
			$context->reply("Player <highlight>{$who}<end> doesn't exist.");
			return;
		}
		if (!$this->isBanned($charId)) {
			$context->reply("<highlight>{$who}<end> is not banned on this bot.");
			return;
		}

		$this->remove($charId);

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
	 * This helper method bans player with given arguments.
	 */
	private function banPlayer(string $who, string $sender, ?int $length, ?string $reason, CommandReply $sendto): bool {
		$toBan = [$who];
		if ($this->settingManager->getBool('ban_all_alts')) {
			$altInfo = $this->altsController->getAltInfo($who);
			$toBan = [$altInfo->main, ...$altInfo->getAllValidatedAlts()];
		}
		$numSuccess = 0;
		$numErrors = 0;
		$msgs = [];
		foreach ($toBan as $who) {
			$charId = $this->chatBot->get_uid($who);
			if (!$charId) {
				$msgs []= "Character <highlight>$who<end> does not exist.";
				$numErrors++;
				continue;
			}

			if ($this->isBanned($charId)) {
				$msgs []= "Character <highlight>$who<end> is already banned.";
				$numErrors++;
				continue;
			}

			if ($this->accessManager->compareCharacterAccessLevels($sender, $who) <= 0) {
				$msgs []= "You must have an access level higher than <highlight>$who<end> ".
					"to perform this action.";
				$numErrors++;
				continue;
			}

			if ($length === 0) {
				return false;
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
			} else {
				$numErrors++;
			}
		}
		if (count($msgs)) {
			$sendto->reply(join("\n", $msgs));
		}
		return $numSuccess > 0;
	}

	/**
	 * Actually add $charId to the banlist with optional duration and reason
	 * @param int $charId The UID of the player to ban
	 * @param string $sender The name of the player banning them
	 * @param null|int $length length of the ban in s, or null/0 for unlimited
	 * @param null|string $reason Optional reason  for the ban
	 * @return bool true on success, false on failure
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
			->asObj(BanEntry::class);
		$bannedUids = $bans->pluck("charid")->toArray();
		$players = $this->playerManager
			->searchByUids($this->db->getDim(), ...$bannedUids)
			->keyBy("charid");
		$bans->each(function (BanEntry $ban) use ($players): void {
			$ban->name = $players->get($ban->charid)?->name ?? (string)$ban->charid;
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

	protected function addOrgToBanlist(BannedOrg $ban, ?CommandReply $sendto=null): void {
		$this->orgbanlist[$ban->org_id] = $ban;
		if (!$this->chatBot->ready) {
			return;
		}
		$this->guildManager->getByIdAsync(
			$ban->org_id,
			null,
			false,
			function (?Guild $guild, BannedOrg $ban, ?CommandReply $sendto): void {
				$ban->org_name = (string)$ban->org_id;
				if (isset($guild)) {
					$ban->org_name = $guild->orgname;
				}
				if (!isset($this->orgbanlist[$ban->org_id])) {
					if (isset($sendto)) {
						$sendto->reply(
							"Not adding <highlight>{$ban->org_name}<end> to the banlist, ".
							"because they were unbanned before we finished looking up data."
						);
					}
					return;
				}
				$this->orgbanlist[$ban->org_id] = $ban;
				if (isset($sendto)) {
					$sendto->reply("Added <highlight>{$ban->org_name}<end> to the banlist.");
				}
			},
			$ban,
			$sendto
		);
	}

	/** Check if $charId is banned */
	public function isBanned(int $charId): bool {
		return isset($this->banlist[$charId]);
	}

	/**
	 * Call either the notbanned ort banned callback for $charId
	 * @psalm-param null|callable(int, mixed...) $notBanned
	 * @psalm-param null|callable(int, mixed...) $banned
	 */
	public function handleBan(int $charId, ?callable $notBanned, ?callable $banned, mixed ...$args): void {
		$notBanned ??= fn(int $charId, mixed ...$args): mixed => null;
		$banned ??= fn(int $charId, mixed ...$args): mixed => null;
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
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($charId, $notBanned, $banned, $args): void {
				if (!isset($whois) || !isset($whois->guild_id)) {
					$notBanned($charId, ...$args);
					return;
				}

				if (isset($this->orgbanlist[$whois->guild_id])) {
					$banned($charId, ...$args);
					return;
				}
				$notBanned($charId, ...$args);
			},
			$player,
		);
	}

	public function orgIsBanned(int $orgId): bool {
		return isset($this->orgbanlist[$orgId]);
	}

	/**
	 * @return array<int,BanEntry>
	 */
	public function getBanlist(): array {
		return $this->banlist;
	}

	/**
	 * List all currently banned org
	 */
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

	/**
	 * Ban a whole organization from the bot by their org id
	 */
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
	): void {
		$this->banOrg($orgId, $duration ? $duration() : null, $context->char->name, $reason, $context);
	}

	/**
	 * @param \Nadybot\Modules\ORGLIST_MODULE\Organization[] $orgs
	 * @param string|null $duration
	 * @param string $reason
	 */
	public function formatOrgsToBan(array $orgs, ?string $duration, string $reason): string {
		$blob = '';
		$banCmd = "/tell <myname> orgban add %d reason {$reason}";
		if (isset($duration)) {
			$banCmd = "/tell <myname> orgban add %d {$duration} reason {$reason}";
		}
		foreach ($orgs as $org) {
			$addLink = $this->text->makeChatcmd('ban', sprintf($banCmd, $org->id));
			$blob .= "<{$org->faction}>{$org->name}<end> ({$org->id}) - {$org->num_members} members [$addLink]\n\n";
		}
		return $blob;
	}

	public function banOrg(int $orgId, ?string $duration, string $bannedBy, string $reason, CommandReply $sendto): bool {
		if ($this->orgIsBanned($orgId)) {
			$sendto->reply(
				"<highlight>" . $this->orgbanlist[$orgId]->org_name.
				"<end> is already banned."
			);
			return false;
		}
		$endDate = null;
		if (isset($duration)) {
			$durationInSecs = $this->util->parseTime($duration);
			if ($durationInSecs === 0) {
				$sendto->reply("<highlight>{$duration}<end> is not a valid duration.");
				return false;
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
		$this->addOrgToBanlist($ban, $sendto);
		return true;
	}

	/**
	 * Remove an organization from the ban list, given their org id
	 */
	#[NCA\HandlesCommand("orgban")]
	#[NCA\Help\Group("ban")]
	public function orgbanRemCommand(CmdContext $context, PRemove $rem, int $orgId): void {
		if (!$this->orgIsBanned($orgId)) {
			$this->guildManager->getByIdAsync(
				$orgId,
				null,
				false,
				function (?Guild $guild, int $orgId, CommandReply $sendto): void {
					if (!isset($guild)) {
						$sendto->reply("<highlight>{$orgId}<end> is not a valid org id.");
						return;
					}
					$sendto->reply("<highlight>{$guild->orgname}<end> is currently not banned.");
				},
				$orgId,
				$context
			);
			return;
		}
		$ban = $this->orgbanlist[$orgId];
		$this->db->table(self::DB_TABLE_BANNED_ORGS)
			->where("org_id", $orgId)
			->delete();
		$context->reply("Removed <highlight>{$ban->org_name}<end> from the banlist.");
		unset($this->orgbanlist[$orgId]);
	}
}
