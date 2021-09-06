<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\{
	Event,
	AccessManager,
	CommandReply,
	DBSchema\Player,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PLAYER_LOOKUP\GuildManager,
	Util,
	Nadybot,
	SettingManager,
	Text,
	DB,
	DBSchema\BanEntry,
	SQLException,
};
use Nadybot\Core\DBSchema\Audit;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'ban',
 *		accessLevel   = 'mod',
 *		description   = 'Ban a character from this bot',
 *		help          = 'ban.txt',
 *		defaultStatus = '1'
 *	)
 *	@DefineCommand(
 *		command       = 'banlist',
 *		accessLevel   = 'mod',
 *		description   = 'Shows who is on the banlist',
 *		help          = 'ban.txt',
 *		defaultStatus = '1'
 *	)
 *	@DefineCommand(
 *		command       = 'unban',
 *		accessLevel   = 'mod',
 *		description   = 'Unban a character from this bot',
 *		help          = 'ban.txt',
 *		defaultStatus = '1'
 *	)
 *	@DefineCommand(
 *		command     = 'orgban',
 *		alias       = 'orgbans',
 *		accessLevel = 'mod',
 *		description = "Ban or unban a whole org",
 *		help        = 'orgban.txt'
 *	)
 */
class BanController {
	public const DB_TABLE = "banlist_<myname>";
	public const DB_TABLE_BANNED_ORGS = "banned_orgs_<myname>";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public GuildManager $guildManager;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

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

	/**
	 * @Setting("notify_banned_player")
	 * @Description("Notify character when banned from bot")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $defaultNotifyBannedPlayer = "1";

	/**
	 * @Setting("ban_all_alts")
	 * @Description("Always ban all alts, not just 1 char")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $defaultBanAllAlts = "0";

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		if ($this->db->schema()->hasTable("players")) {
			$this->uploadBanlist();
		}
		$this->uploadOrgBanlist();
	}

	/**
	 * @Event("connect")
	 * @Description("Upload banlist into memory")
	 * @DefaultStatus("1")
	 */
	public function initializeBanList(Event $eventObj): void {
		$this->uploadBanlist();
		$this->uploadOrgBanlist();
	}

	/**
	 * Command parameters are:
	 *  - name of the character
	 *  - time of ban
	 *  - banning reason string
	 *
	 * @HandlesCommand("ban")
	 * @Matches("/^ban ([a-z0-9-]+) ([a-z0-9]+) (for|reason) (.+)$/i")
	 */
	public function banPlayerWithTimeAndReasonCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));
		$length = $this->util->parseTime($args[2]);
		$reason = $args[4];

		if (!$this->banPlayer($who, $sender, $length, $reason, $sendto)) {
			return;
		}

		$timeString = $this->util->unixtimeToReadable($length);
		$sendto->reply("You have banned <highlight>{$who}<end> from this bot for {$timeString}.");
		if (!$this->settingManager->getBool('notify_banned_player')) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been banned from this bot by <highlight>{$sender}<end> for {$timeString}. ".
			"Reason: $reason",
			$who
		);
	}

	/**
	 * This command handler bans a player from this bot.
	 *
	 * Command parameters are:
	 *  - name of the player
	 *  - time of ban
	 *
	 * @HandlesCommand("ban")
	 * @Matches("/^ban ([a-z0-9-]+) ([a-z0-9]+)$/i")
	 */
	public function banPlayerWithTimeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));
		$length = $this->util->parseTime($args[2]);

		if (!$this->banPlayer($who, $sender, $length, '', $sendto)) {
			return;
		}

		$timeString = $this->util->unixtimeToReadable($length);
		$sendto->reply("You have banned <highlight>{$who}<end> from this bot for {$timeString}.");
		if (!$this->settingManager->getBool('notify_banned_player')) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been banned from this bot by <highlight>{$sender}<end> for {$timeString}.",
			$who
		);
	}

	/**
	 * This command handler bans a player from this bot.
	 *
	 * Command parameters are:
	 *  - name of the player
	 *  - banning reason string
	 *
	 * @HandlesCommand("ban")
	 * @Matches("/^ban ([a-z0-9-]+) (for|reason) (.+)$/i")
	 */
	public function banPlayerWithReasonCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));
		$reason = $args[3];

		if (!$this->banPlayer($who, $sender, null, $reason, $sendto)) {
			return;
		}

		$sendto->reply("You have permanently banned <highlight>{$who}<end> from this bot.");
		if (!$this->settingManager->getBool('notify_banned_player')) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been permanently banned from this bot by <highlight>{$sender}<end>. ".
			"Reason: {$reason}",
			$who
		);
	}

	/**
	 * This command handler bans a player from this bot.
	 *
	 * Command parameter is:
	 *  - name of the player
	 *
	 * @HandlesCommand("ban")
	 * @Matches("/^ban ([a-z0-9-]+)$/i")
	 */
	public function banPlayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));

		if (!$this->banPlayer($who, $sender, null, '', $sendto)) {
			return;
		}

		$sendto->reply("You have permanently banned <highlight>{$who}<end> from this bot.");
		if (!$this->settingManager->getBool('notify_banned_player')) {
			return;
		}
		$this->chatBot->sendMassTell(
			"You have been permanently banned from this bot by <highlight>{$sender}<end>.",
			$who
		);
	}

	/**
	 * This command handler shows who is on the banlist.
	 *
	 * @HandlesCommand("banlist")
	 * @Matches("/^banlist$/i")
	 */
	public function banlistCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$banlist = $this->getBanlist();
		$count = count($banlist);
		if ($count === 0) {
			$msg = "No one is currently banned from this bot.";
			$sendto->reply($msg);
			return;
		}
		$bans = [];
		foreach ($banlist as $ban) {
			$blob = "<header2>{$ban->name}<end>\n";
			$blob .= "<tab>Date: <highlight>" . $this->util->date($ban->time) . "<end>\n";
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
		$sendto->reply($msg);
	}

	/**
	 * This command handler unbans a player and all their alts from this bot.
	 *
	 * Command parameter is:
	 *  - name of one of the player's characters
	 *
	 * @HandlesCommand("unban")
	 * @Matches("/^unban all (.+)$/i")
	 */
	public function unbanAllCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));

		$charId = $this->chatBot->get_uid($who);
		if (!$charId) {
			$sendto->reply("Player <highlight>{$who}<end> doesn't exist.");
			return;
		}
		if (!$this->isBanned($charId)) {
			$sendto->reply("<highlight>$who<end> is not banned on this bot.");
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

		$sendto->reply("You have unbanned <highlight>{$who}<end> and all their alts from this bot.");
		if ($this->settingManager->getBool('notify_banned_player')) {
			$this->chatBot->sendMassTell("You have been unbanned from this bot by $sender.", $who);
		}
	}

	/**
	 * This command handler unbans a player from this bot.
	 *
	 * Command parameter is:
	 *  - name of the player
	 *
	 * @HandlesCommand("unban")
	 * @Matches("/^unban (.+)$/i")
	 */
	public function unbanCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$who = ucfirst(strtolower($args[1]));

		$charId = $this->chatBot->get_uid($who);
		if (!$charId) {
			$sendto->reply("Player <highlight>{$who}<end> doesn't exist.");
			return;
		}
		if (!$this->isBanned($charId)) {
			$sendto->reply("<highlight>{$who}<end> is not banned on this bot.");
			return;
		}

		$this->remove($charId);

		$sendto->reply("You have unbanned <highlight>$who<end> from this bot.");
		if ($this->settingManager->getBool('notify_banned_player')) {
			$this->chatBot->sendMassTell("You have been unbanned from this bot by $sender.", $who);
		}
	}

	/**
	 * @Event("timer(1min)")
	 * @Description("Check temp bans to see if they have expired")
	 * @DefaultStatus("1")
	 */
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
	 *
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
			$banEnd = time() + (int)$length;
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
		$audit->actee = $this->chatBot->get_uid($charId);
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

		$query = $this->db->table(self::DB_TABLE, "b")
			->leftJoin("players AS p", "b.charid", "p.charid");
		$query
			->select("b.*", "p.name")
			->asObj(BanEntry::class)->each(function(BanEntry $row) {
				$row->name ??= (string)$row->charid;
				$this->banlist[$row->charid] = $row;
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

	/** Call either the notbanned ort banned callback for $charId */
	public function handleBan(int $charId, ?callable $notBanned, ?callable $banned, ...$args): void {
		$notBanned ??= fn() => null;
		$banned ??= fn() => null;
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
		$player = $this->chatBot->id[$charId];
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
	 * @HandlesCommand("orgban")
	 * @Matches("/^orgban$/i")
	 */
	public function orgbanListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blocks = [];
		foreach ($this->orgbanlist as $orgIf => $ban) {
			$blocks []= $this->renderBannedOrg($ban);
		}
		$count = count($blocks);
		if ($count === 0) {
			$sendto->reply("No orgs are banned at the moment");
			return;
		}
		$msg = $this->text->makeBlob(
			"Banned orgs ({$count})",
			join("\n", $blocks)
		);
		$sendto->reply($msg);
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
	 * @HandlesCommand("orgban")
	 * @Matches("/^orgban add (?<orgid>\d+) (?<duration>(?:\d+[a-z])+) (?:for|reason|because) (?<reason>.+)$/i")
	 * @Matches("/^orgban add (?<orgid>\d+) (?:for|reason|because) (?<reason>.+)$/i")
	 */
	public function orgbanAddByIdCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->banOrg((int)$args["orgid"], $args["duration"]??null, $sender, $args["reason"], $sendto);
	}

	/**
	 * @param Organization[] $orgs
	 * @param string|null $duration
	 * @param string $reason
	 */
	public function formatOrgsToBan(array $orgs, ?string $duration, string $reason): string {
		$blob = '';
		$banCmd = "/tell <myname> orgban add %d reason {$reason}";
		if (isset($banCmd)) {
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
		$this->db->insert(self::DB_TABLE_BANNED_ORGS, $ban, null);
		$this->addOrgToBanlist($ban, $sendto);
		return true;
	}

	/**
	 * @HandlesCommand("orgban")
	 * @Matches("/^orgban rem (?<orgid>\d+)$/i")
	 */
	public function orgbanRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$orgId = (int)$args["orgid"];
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
				$sendto
			);
			return;
		}
		$ban = $this->orgbanlist[$orgId];
		$this->db->table(self::DB_TABLE_BANNED_ORGS)
			->where("org_id", $orgId)
			->delete();
		$sendto->reply("Removed <highlight>{$ban->org_name}<end> from the banlist.");
		unset($this->orgbanlist[$orgId]);
	}
}
