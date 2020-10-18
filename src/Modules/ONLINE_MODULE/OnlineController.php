<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\{
	AccessManager,
	BuddylistManager,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	StopExecutionException,
	Text,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Naturarum (Paradise, RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'online',
 *		accessLevel = 'member',
 *		description = 'Shows who is online',
 *		help        = 'online.txt'
 *	)
 * @ProvidesEvent("online(org)")
 * @ProvidesEvent("offline(org)")
 */
class OnlineController {
	protected const GROUP_OFF = 0;
	protected const GROUP_BY_MAIN = 1;
	protected const GROUP_BY_PROFESSION = 2;

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;
	
	/** @Inject */
	public AccessManager $accessManager;
	
	/** @Inject */
	public BuddylistManager $buddylistManager;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;

	/** @Inject */
	public CommandAlias $commandAlias;
	
	/** @Logger */
	public LoggerWrapper $logger;
	
	/** @Setup */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "online");
		
		$this->settingManager->add(
			$this->moduleName,
			"online_expire",
			"How long to wait before clearing online list",
			"edit",
			"time",
			"15m",
			"2m;5m;10m;15m;20m",
			'',
			"mod"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_show_org_guild",
			"Show org/rank for players in guild channel",
			"edit",
			"options",
			"1",
			"Show org and rank;Show rank only;Show org only;Show no org info",
			"2;1;3;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_show_org_priv",
			"Show org/rank for players in private channel",
			"edit",
			"options",
			"2",
			"Show org and rank;Show rank only;Show org only;Show no org info",
			"2;1;3;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_admin",
			"Show admin levels in online list",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_group_by",
			"Group online list by",
			"edit",
			"options",
			"1",
			"do not group;player;profession",
			"0;1;2"
		);

		$this->commandAlias->register($this->moduleName, "online", "o");
		$this->commandAlias->register($this->moduleName, "online", "sm");
	}
	
	/**
	 * @HandlesCommand("online")
	 * @Matches("/^online$/i")
	 */
	public function onlineCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getOnlineList();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("online")
	 * @Matches("/^online (.+)$/i")
	 */
	public function onlineProfCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$profession = $this->util->getProfessionName($args[1]);
		if (empty($profession)) {
			$msg = "<highlight>{$args[1]}<end> is not a recognized profession.";
			$sendto->reply($msg);
			return;
		}

		$sql = "SELECT DISTINCT p.*, o.afk, COALESCE(a.main, o.name) AS pmain, ".
				"(CASE WHEN o2.name IS NULL THEN 0 ELSE 1 END) AS online ".
			"FROM online o ".
			"LEFT JOIN alts a ON (o.name = a.alt AND a.validated IS TRUE) ".
			"LEFT JOIN alts a2 ON a2.main = COALESCE(a.main, o.name) ".
			"LEFT JOIN players p ON a2.alt = p.name OR COALESCE(a.main, o.name) = p.name ".
			"LEFT JOIN online o2 ON p.name = o2.name ".
			"WHERE p.profession = ? ".
			"ORDER BY COALESCE(a.main, o.name) ASC";
		/** @var OnlinePlayer[] */
		$players = $this->db->fetchAll(OnlinePlayer::class, $sql, $profession);
		$count = count($players);
		$mainCount = 0;
		$currentMain = "";
		$blob = "";

		if ($count === 0) {
			$msg = "$profession Search Results (0)";
			$sendto->reply($msg);
			return;
		}
		foreach ($players as $player) {
			if ($currentMain !== $player->pmain) {
				$mainCount++;
				$blob .= "\n<highlight>$player->pmain<end> has\n";
				$currentMain = $player->pmain;
			}

			$playerName = $player->name;
			if ($player->online) {
				$playerName = $this->text->makeChatcmd($player->name, "/tell {$player->name}");
			}
			if ($player->profession === null) {
				$blob .= "<tab>($playerName)\n";
			} else {
				$prof = $this->util->getProfessionAbbreviation($player->profession);
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->getProfessionId($player->profession).">";
				$blob.= "<tab>$profIcon $playerName - $player->level/<green>$player->ai_level<end> $prof";
			}
			if ($player->online) {
				$blob .= " <green>Online<end>";
			}
			$blob .= "\n";
		}
		$blob .= "\nWritten by Naturarum (RK2)";
		$msg = $this->text->makeBlob("$profession Search Results ($mainCount)", $blob);

		$sendto->reply($msg);
	}
	
	/**
	 * @Event("logOn")
	 * @Description("Records an org member login in db")
	 */
	public function recordLogonEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])) {
			return;
		}
		$player = $this->addPlayerToOnlineList($sender, $this->chatBot->vars['my_guild'], 'guild');
		if ($player === null) {
			return;
		}
		$event = new OnlineEvent();
		$event->type = "online(org)";
		$event->channel = "org";
		$event->player = $player;
		$this->eventManager->fireEvent($event);
	}
	
	/**
	 * @Event("logOff")
	 * @Description("Records an org member logoff in db")
	 */
	public function recordLogoffEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (isset($this->chatBot->guildmembers[$sender])) {
			$this->removePlayerFromOnlineList($sender, 'guild');
			$event = new OfflineEvent();
			$event->type = "offline(org)";
			$event->player = $sender;
			$event->channel = "org";
			$this->eventManager->fireEvent($event);
		}
	}
	
	/**
	 * @Event("logOn")
	 * @Description("Sends a tell to players on logon showing who is online in org")
	 */
	public function showOnlineOnLogonEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (isset($this->chatBot->guildmembers[$sender]) && $this->chatBot->isReady()) {
			$msg = $this->getOnlineList();
			$this->chatBot->sendTell($msg, $sender);
		}
	}
	
	/**
	 * @Event("timer(10mins)")
	 * @Description("Online check")
	 */
	public function onlineCheckEvent(Event $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$data = $this->db->query("SELECT name, channel_type FROM `online`");

		$guildArray = [];
		$privArray = [];

		foreach ($data as $row) {
			switch ($row->channel_type) {
				case 'guild':
					$guildArray []= $row->name;
					break;
				case 'priv':
					$privArray []= $row->name;
					break;
				default:
					$this->logger->log("WARN", "Unknown channel type: '$row->channel_type'. Expected: 'guild' or 'priv'");
			}
		}

		$time = time();

		foreach ($this->chatBot->guildmembers as $name => $rank) {
			if ($this->buddylistManager->isOnline($name)) {
				if (in_array($name, $guildArray)) {
					$sql = "UPDATE `online` SET `dt` = ? WHERE `name` = ? AND added_by = '<myname>' AND channel_type = 'guild'";
					$this->db->exec($sql, $time, $name);
				} else {
					$sql = "INSERT INTO `online` (`name`, `channel`,  `channel_type`, `added_by`, `dt`) VALUES (?, '<myguild>', 'guild', '<myname>', ?)";
					$this->db->exec($sql, $name, $time);
				}
			}
		}

		foreach ($this->chatBot->chatlist as $name => $value) {
			if (in_array($name, $privArray)) {
				$sql = "UPDATE `online` SET `dt` = ? WHERE `name` = ? AND added_by = '<myname>' AND channel_type = 'priv'";
				$this->db->exec($sql, $time, $name);
			} else {
				$sql = "INSERT INTO `online` (`name`, `channel`,  `channel_type`, `added_by`, `dt`) VALUES (?, '<myguild> Guest', 'priv', '<myname>', ?)";
				$this->db->exec($sql, $name, $time);
			}
		}

		$sql = "DELETE FROM `online` WHERE (`dt` < ? AND added_by = '<myname>') OR (`dt` < ?)";
		$this->db->exec($sql, $time, ($time - $this->settingManager->get('online_expire')));
	}
	
	/**
	 * @Event("priv")
	 * @Description("Afk check")
	 * @Help("afk")
	 */
	public function afkCheckPrivateChannelEvent(Event $eventObj) {
		$this->afkCheck($eventObj->sender, $eventObj->message, $eventObj->type);
	}
	
	/**
	 * @Event("guild")
	 * @Description("Afk check")
	 * @Help("afk")
	 */
	public function afkCheckGuildChannelEvent(Event $eventObj) {
		$this->afkCheck($eventObj->sender, $eventObj->message, $eventObj->type);
	}
	
	/**
	 * @Event("priv")
	 * @Description("Sets a member afk")
	 * @Help("afk")
	 */
	public function afkPrivateChannelEvent(Event $eventObj) {
		$this->afk($eventObj->sender, $eventObj->message, $eventObj->type);
	}
	
	/**
	 * @Event("guild")
	 * @Description("Sets a member afk")
	 * @Help("afk")
	 */
	public function afkGuildChannelEvent(Event $eventObj): void {
		$this->afk($eventObj->sender, $eventObj->message, $eventObj->type);
	}
	
	/**
	 * Set someone back from afk if needed
	 */
	public function afkCheck($sender, string $message, string $type): void {
		// to stop raising and lowering the cloak messages from triggering afk check
		if (!$this->util->isValidSender($sender)) {
			return;
		}

		$symbol = $this->settingManager->getString('symbol');
		if (preg_match("/^\Q$symbol\E?afk(.*)$/i", $message)) {
			return;
		}
		$row = $this->db->queryRow("SELECT afk FROM online WHERE `name` = ? AND added_by = '<myname>' AND channel_type = ?", $sender, $type);

		if ($row === null || $row->afk === '') {
			return;
		}
		$time = explode('|', $row->afk)[0];
		$timeString = $this->util->unixtimeToReadable(time() - $time);
		// $sender is back
		$this->db->exec("UPDATE online SET `afk` = '' WHERE `name` = ? AND added_by = '<myname>' AND channel_type = ?", $sender, $type);
		$msg = "<highlight>{$sender}<end> is back after $timeString.";
		
		if ('priv' == $type) {
			$this->chatBot->sendPrivate($msg);
		} elseif ('guild' == $type) {
			$this->chatBot->sendGuild($msg);
		}
	}
	
	public function afk(string $sender, string $message, string $type): void {
		$msg = null;
		$symbol = $this->settingManager->getString('symbol');
		if (preg_match("/^\Q$symbol\E?afk$/i", $message)) {
			$reason = time();
			$this->db->exec("UPDATE online SET `afk` = ? WHERE `name` = ? AND added_by = '<myname>' AND channel_type = ?", $reason, $sender, $type);
			$msg = "<highlight>$sender<end> is now AFK.";
		} elseif (preg_match("/^\Q$symbol\E?brb(.*)$/i", $message, $arr)) {
			$reason = time() . '|brb ' . trim($arr[1]);
			$this->db->exec("UPDATE online SET `afk` = ? WHERE `name` = ? AND added_by = '<myname>' AND channel_type = ?", $reason, $sender, $type);
		} elseif (preg_match("/^\Q$symbol\E?afk[, ]+(.*)$/i", $message, $arr)) {
			$reason = time() . '|' . $arr[1];
			$this->db->exec("UPDATE online SET `afk` = ? WHERE `name` = ? AND added_by = '<myname>' AND channel_type = ?", $reason, $sender, $type);
			$msg = "<highlight>$sender<end> is now AFK.";
		}

		if ($msg !== null) {
			if ('priv' == $type) {
				$this->chatBot->sendPrivate($msg);
			} elseif ('guild' == $type) {
				$this->chatBot->sendGuild($msg);
			}
			
			// if 'afk' was used as a command, throw StopExecutionException to prevent
			// normal command handling from occurring
			if ($message[0] == $symbol) {
				throw new StopExecutionException();
			}
		}
	}
	
	public function addPlayerToOnlineList(string $sender, string $channel, string $channelType): ?OnlinePlayer {
		$sql = "SELECT name FROM `online` ".
			"WHERE `name` = ? AND `channel_type` = ? AND added_by = '<myname>'";
		$data = $this->db->query($sql, $sender, $channelType);
		if (count($data) === 0) {
			$sql = "INSERT INTO `online` ".
				"(`name`, `channel`,  `channel_type`, `added_by`, `dt`) ".
				"VALUES (?, ?, ?, '<myname>', ?)";
			$this->db->exec($sql, $sender, $channel, $channelType, time());
		}
		$sql = "SELECT p.*, o.name, o.afk, COALESCE(a.main, o.name) AS pmain ".
			"FROM online o ".
			"LEFT JOIN alts a ON (o.name = a.alt AND a.validated IS TRUE) ".
			"LEFT JOIN players p ON o.name = p.name ".
			"WHERE o.channel_type=? AND o.name=?";
		return $this->db->fetch(OnlinePlayer::class, $sql, $channel, $sender);
	}
	
	public function removePlayerFromOnlineList(string $sender, string $channelType): void {
		$sql = "DELETE FROM `online` ".
			"WHERE `name` = ? AND `channel_type` = ? AND added_by = '<myname>'";
		$this->db->exec($sql, $sender, $channelType);
	}
	
	/**
	 * Get a page or multiple pages with the online list
	 * @return string[]
	 */
	public function getOnlineList(): array {
		$orgData = $this->getPlayers('guild');
		$orgList = $this->formatData($orgData, $this->settingManager->getInt("online_show_org_guild"));

		$privData = $this->getPlayers('priv');
		$privList = $this->formatData($privData, $this->settingManager->getInt("online_show_org_priv"));

		$totalCount = $orgList->count + $privList->count;
		$totalMain = $orgList->countMains + $privList->countMains;

		$blob = "\n";
		if ($orgList->count > 0) {
			$blob .= "<header2>Org Channel ($orgList->countMains)<end>\n";
			$blob .= $orgList->blob;
			$blob .= "\n\n";
		}
		if ($privList->count > 0) {
			$blob .= "<header2>Private Channel ($privList->countMains)<end>\n";
			$blob .= $privList->blob;
			$blob .= "\n\n";
		}

		if ($totalCount > 0) {
			$blob .= "Originally written by Naturarum (RK2)";
			$msg = $this->text->makeBlob("Players Online ($totalMain)", $blob);
		} else {
			$msg = "Players Online (0)";
		}
		return (array)$msg;
	}

	public function getOrgInfo(int $showOrgInfo, string $fancyColon, string $guild, string $guild_rank): string {
		switch ($showOrgInfo) {
			case 3:
				  return $guild !== "" ? " $fancyColon {$guild}":" $fancyColon Not in an org";
			case 2:
				  return $guild !== "" ? " $fancyColon {$guild} ({$guild_rank})":" $fancyColon Not in an org";
			case 1:
				  return $guild !== "" ? " $fancyColon {$guild_rank}":"";
			default:
				  return "";
		}
	}

	public function getAdminInfo(string $name, string $fancyColon): string {
		if (!$this->settingManager->getBool("online_admin")) {
			return "";
		}

		$accessLevel = $this->accessManager->getAccessLevelForCharacter($name);
		switch ($accessLevel) {
			case 'superadmin':
				  return " $fancyColon <red>SuperAdmin<end>";
			case 'admin':
				  return " $fancyColon <red>Admin<end>";
			case 'mod':
				  return " $fancyColon <green>Mod<end>";
			case 'rl':
				  return " $fancyColon <orange>RL<end>";
		}
		if (substr($accessLevel, 0, 5) === "raid_") {
			$setName = $this->settingManager->getString("name_{$accessLevel}");
			if ($setName !== null) {
				return " $fancyColon <orange>$setName<end>";
			}
		}
		return "";
	}

	public function getAfkInfo(string $afk, string $fancyColon): string {
		if (empty($afk)) {
			return '';
		}
		$props = explode("|", $afk, 2);
		if (count($props) === 1 || !strlen($props[1])) {
			$timeString = $this->util->unixtimeToReadable(time() - $props[0], false);
			return " $fancyColon <highlight>AFK for $timeString<end>";
		}
		$timeString = $this->util->unixtimeToReadable(time() - $props[0], false);
		return " $fancyColon <highlight>AFK for $timeString: {$props[1]}<end>";
	}

	/**
	 * @param OnlinePlayer[] $players
	 * @param int $showOrgInfo
	 * @return OnlineList
	 */
	public function formatData(array $players, int $showOrgInfo): OnlineList {
		$currentGroup = "";
		$separator = "-";
		$list = new OnlineList();
		$list->count = count($players);
		$list->countMains = 0;
		$list->blob = "";

		if ($list->count === 0) {
			return $list;
		}
		$groupBy = $this->settingManager->getInt('online_group_by');
		foreach ($players as $player) {
			if ($groupBy === static::GROUP_BY_MAIN) {
				if ($currentGroup !== $player->pmain) {
					$list->countMains++;
					$list->blob .= "\n<pagebreak><highlight>$player->pmain<end> on\n";
					$currentGroup = $player->pmain;
				}
			} elseif ($groupBy === static::GROUP_BY_PROFESSION) {
				$list->countMains++;
				if ($currentGroup !== $player->profession) {
					$profIcon = "?";
					if ($player->profession !== null) {
						$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->getProfessionId($player->profession).">";
					}
					$list->blob .= "\n<pagebreak>{$profIcon}<highlight>{$player->profession}<end>\n";
					$currentGroup = $player->profession;
				}
			} else {
				$list->countMains++;
			}

			$admin = $this->getAdminInfo($player->name, $separator);
			$afk = $this->getAfkInfo($player->afk, $separator);

			if ($player->profession === null) {
				$list->blob .= "<tab>? $player->name$admin$afk\n";
			} else {
				$prof = $this->util->getProfessionAbbreviation($player->profession);
				$orgRank = $this->getOrgInfo($showOrgInfo, $separator, $player->guild, $player->guild_rank);
				$profIcon = "";
				if ($groupBy !== static::GROUP_BY_PROFESSION) {
					$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->getProfessionId($player->profession)."> ";
				}
				$list->blob.= "<tab>{$profIcon}{$player->name} - {$player->level}/<green>{$player->ai_level}<end> {$prof}{$orgRank}{$admin}{$afk}\n";
			}
		}

		return $list;
	}

	/**
	 * @return OnlinePlayer[]
	 */
	public function getPlayers(string $channelType): array {
		$groupBy = $this->settingManager->getInt('online_group_by');
		$sql = "SELECT p.*, o.name, o.afk, COALESCE(a.main, o.name) AS pmain ".
			"FROM online o ".
			"LEFT JOIN alts a ON (o.name = a.alt AND a.validated IS TRUE) ".
			"LEFT JOIN players p ON o.name = p.name ".
			"WHERE o.channel_type = ? ";
		if ($groupBy === static::GROUP_BY_MAIN) {
			$sql .= "ORDER BY COALESCE(a.main, o.name) ASC";
		} elseif ($groupBy === static::GROUP_BY_PROFESSION) {
			$sql .= "ORDER BY COALESCE(p.profession, 'Unknown') ASC, o.name ASC";
		} else {
			$sql .= "ORDER BY o.name ASC";
		}
		return $this->db->fetchAll(OnlinePLayer::class, $sql, $channelType);
	}

	public function getProfessionId(string $profession): ?int {
		$profToID = [
			"Adventurer" => 6,
			"Agent" => 5,
			"Bureaucrat" => 8,
			"Doctor" => 10,
			"Enforcer" => 9,
			"Engineer" => 3,
			"Fixer" => 4,
			"Keeper" => 14,
			"Martial Artist" => 2,
			"Meta-Physicist" => 12,
			"Nano-Technician" => 11,
			"Soldier" => 1,
			"Shade" => 15,
			"Trader" => 7,
		];
		return $profToID[$profession] ?? null;
	}

	/**
	 * Get a list of all people online in all linked channels
	 * @Api("/online")
	 * @GET
	 * @AccessLevelFrom("online")
	 * @ApiResult(code=200, class='OnlinePlayers', desc='A list of online players')
	 */
	public function apiOnlineEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$result = new OnlinePlayers();
		$result->org = $this->getPlayers('guild');
		$result->private_channel = $this->getPlayers('priv');
		return new ApiResponse($result);
	}
}
