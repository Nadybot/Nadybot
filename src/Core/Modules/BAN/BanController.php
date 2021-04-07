<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\{
	Event,
	AccessManager,
	CommandReply,
	Modules\ALTS\AltsController,
	Util,
	Nadybot,
	SettingManager,
	Text,
	DB,
	DBSchema\BanEntry,
	SQLException,
};

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
 */
class BanController {

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
		$this->db->loadSQLFile($this->moduleName, "banlist");
		if ($this->db->tableExists("players")) {
			$this->uploadBanlist();
		}
	}

	/**
	 * @Event("connect")
	 * @Description("Upload banlist into memory")
	 * @DefaultStatus("1")
	 */
	public function initializeBanList(Event $eventObj): void {
		$this->uploadBanlist();
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
		$numRows = $this->db->exec(
			"DELETE FROM `banlist_<myname>` ".
			"WHERE `banend` IS NOT NULL AND `banend` != 0 AND `banend` < ?",
			time()
		);

		if ($numRows > 0) {
			$this->uploadBanlist();
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

		$sql = "INSERT INTO `banlist_<myname>` (`charid`, `admin`, `time`, `reason`, `banend`) VALUES (?, ?, ?, ?, ?)";
		$numrows = $this->db->exec($sql, $charId, $sender, time(), $reason, $banEnd);

		$this->uploadBanlist();

		return $numrows > 0;
	}

	/** Remove $charId from the banlist */
	public function remove(int $charId): bool {
		$sql = "DELETE FROM `banlist_<myname>` WHERE `charid` = ?";
		$numrows = $this->db->exec($sql, $charId);

		$this->uploadBanlist();

		return $numrows > 0;
	}

	/** Sync the banlist from the database */
	public function uploadBanlist(): void {
		$this->banlist = [];

		$sql = "SELECT b.*, IFNULL(p.name, b.charid) AS name ".
			"FROM `banlist_<myname>` b LEFT JOIN players p ON b.charid = p.charid";
		/** @var BanEntry[] */
		$data = $this->db->fetchAll(BanEntry::class, $sql);
		foreach ($data as $row) {
			$this->banlist[$row->charid] = $row;
		}
	}

	/** Check if $charId is banned */
	public function isBanned(int $charId): bool {
		return isset($this->banlist[$charId]);
	}

	/**
	 * @return array<int,BanEntry>
	 */
	public function getBanlist(): array {
		return $this->banlist;
	}
}
