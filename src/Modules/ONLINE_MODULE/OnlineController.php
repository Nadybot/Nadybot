<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	AOChatEvent,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	ConfigFile,
	DB,
	Event,
	EventManager,
	ModuleInstance,
	LoggerWrapper,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	QueryBuilder,
	Registry,
	SettingManager,
	StopExecutionException,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Modules\{
	DISCORD_GATEWAY_MODULE\DiscordGatewayController,
	RAID_MODULE\RaidController,
	RELAY_MODULE\RelayController,
	RELAY_MODULE\Relay,
	WEBSERVER_MODULE\ApiResponse,
	WEBSERVER_MODULE\HttpProtocolWrapper,
	WEBSERVER_MODULE\Request,
	WEBSERVER_MODULE\Response,
	WEBSERVER_MODULE\StatsController,
};

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Naturarum (Paradise, RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "online",
		accessLevel: "guest",
		description: "Shows who is online",
		alias: ['o', 'sm'],
	),
	NCA\ProvidesEvent("online(org)"),
	NCA\ProvidesEvent("offline(org)")
]
class OnlineController extends ModuleInstance {
	protected const GROUP_OFF = 0;
	protected const GROUP_BY_MAIN = 1;
	protected const GROUP_BY_ORG = 1;
	protected const GROUP_BY_PROFESSION = 2;
	protected const GROUP_BY_FACTION = 3;

	protected const RELAY_OFF = 0;
	protected const RELAY_YES = 1;
	protected const RELAY_SEPARATE = 2;

	protected const RAID_OFF = 0;
	protected const RAID_IN = 1;
	protected const RAID_NOT_IN = 2;
	protected const RAID_COMPACT = 4;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public DiscordGatewayController $discordGatewayController;

	#[NCA\Inject]
	public RaidController $raidController;

	#[NCA\Inject]
	public RelayController $relayController;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public StatsController $statsController;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->table("online")
			->where("added_by", $this->db->getBotname())
			->delete();

		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_expire",
			description: "How long to wait before clearing online list",
			mode: "edit",
			type: "time",
			value: "15m",
			options: "2m;5m;10m;15m;20m",
			intoptions: '',
			accessLevel: "mod"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_show_relay",
			description: "Include players from your relay(s) by default",
			mode: "edit",
			type: "options",
			value: "0",
			options: "No;Always;In a separate message",
			intoptions: "0;1;2"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_show_org_guild",
			description: "Show org/rank for players in guild channel",
			mode: "edit",
			type: "options",
			value: "1",
			options: "Show org and rank;Show rank only;Show org only;Show no org info",
			intoptions: "2;1;3;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_show_org_guild_relay",
			description: "Show org/rank for players in your relays",
			mode: "edit",
			type: "options",
			value: "0",
			options: "Show org and rank;Show rank only;Show org only;Show no org info",
			intoptions: "2;1;3;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_show_org_priv",
			description: "Show org/rank for players in private channel",
			mode: "edit",
			type: "options",
			value: "2",
			options: "Show org and rank;Show rank only;Show org only;Show no org info",
			intoptions: "2;1;3;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_admin",
			description: "Show admin levels in online list",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_raid",
			description: "Show raid participation in online list",
			mode: "edit",
			type: "options",
			value: "0",
			options: "off;in raid;not in raid;both;both, but compact",
			intoptions: "0;1;2;3;7"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_group_by",
			description: "Group online list by",
			mode: "edit",
			type: "options",
			value: "1",
			options: "do not group;player;profession;faction",
			intoptions: "0;1;2;3"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_relay_group_by",
			description: "Group relay online list by",
			mode: "edit",
			type: "options",
			value: "1",
			options: "do not group;org;profession",
			intoptions: "0;1;2"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "online_show_discord",
			description: "Show players in discord voice channels",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "afk_brb_without_symbol",
			description: "React to afk and brb even without command prefix",
			mode: "edit",
			type: "options",
			value: "1",
			options: "true;false",
			intoptions: "1;0"
		);

		$onlineOrg = new OnlineOrgStats();
		$onlinePriv = new OnlinePrivStats();
		Registry::injectDependencies($onlineOrg);
		Registry::injectDependencies($onlinePriv);
		$this->statsController->registerProvider($onlineOrg, "online");
		$this->statsController->registerProvider($onlinePriv, "online");
	}

	public function buildOnlineQuery(string $sender, string $channelType): QueryBuilder {
		return $this->db->table("online")
			->where("name", $sender)
			->where("channel_type", $channelType)
			->where("added_by", $this->db->getBotname());
	}

	/** Show a full list of players online with their alts */
	#[NCA\HandlesCommand("online")]
	public function onlineCommand(CmdContext $context): void {
		$msg = $this->getOnlineList();
		$context->reply($msg);
	}

	/** Show a full list of players online, including other bots you share online list with */
	#[NCA\HandlesCommand("online")]
	public function onlineAllCommand(CmdContext $context, #[NCA\Str("all")] string $action): void {
		$msg = $this->getOnlineList(1);
		$context->reply($msg);
	}

	/** Show a list of players that have the specified profession as an alt */
	#[NCA\HandlesCommand("online")]
	public function onlineProfCommand(CmdContext $context, string $profName): void {
		$profession = $this->util->getProfessionName($profName);
		if (empty($profession)) {
			$msg = "<highlight>{$profName}<end> is not a recognized profession.";
			$context->reply($msg);
			return;
		}

		$onlineChars = $this->db->table("online")->asObj(Online::class);
		$onlineByName = $onlineChars->keyBy("name");
		/** @var Collection<string> */
		$mains = $onlineChars->map(function (Online $online): string {
			return $this->altsController->getMainOf($online->name);
		})->unique();
		/** @var Collection<OnlinePlayer> */
		$players = new Collection();
		foreach ($mains as $main) {
			$alts = $this->altsController->getAltsOf($main);
			$chars = $this->playerManager->searchByNames($this->db->getDim(), ...$alts)
				->where("profession", $profession);
			if ($chars->isEmpty()) {
				continue;
			}
			foreach ($chars as $char) {
				$onlineChar = OnlinePlayer::fromPlayer($char, $onlineByName->get($char->name));
				$onlineChar->pmain = $main;
				$players->push($onlineChar);
			}
		}
		$players = $players->sortBy("name")->sortBy("pmain");

		$count = $players->count();
		$mainCount = 0;
		$currentMain = "";
		$blob = "";

		if ($count === 0) {
			$msg = "$profession Search Results (0)";
			$context->reply($msg);
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
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".($this->getProfessionId($player->profession)??0).">";
				$blob.= "<tab>$profIcon $playerName - $player->level/<green>$player->ai_level<end> $prof";
			}
			if ($player->online) {
				$blob .= " <green>Online<end>";
			}
			$blob .= "\n";
		}
		$blob .= "\nWritten by Naturarum (RK2)";
		$msg = $this->text->makeBlob("$profession Search Results ($mainCount)", $blob);

		$context->reply($msg);
	}

	#[NCA\Event(
		name: "logOn",
		description: "Records an org member login in db"
	)]
	public function recordLogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender]) || !is_string($sender)) {
			return;
		}
		$player = $this->addPlayerToOnlineList($sender, $this->config->orgName, 'guild');
		if ($player === null) {
			return;
		}
		$event = new OnlineEvent();
		$event->type = "online(org)";
		$event->channel = "org";
		$event->player = $player;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: "logOff",
		description: "Records an org member logoff in db"
	)]
	public function recordLogoffEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender]) || !is_string($sender)) {
			return;
		}
		$this->removePlayerFromOnlineList($sender, 'guild');
		$event = new OfflineEvent();
		$event->type = "offline(org)";
		$event->player = $sender;
		$event->channel = "org";
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: "logOn",
		description: "Sends a tell to players on logon showing who is online in org"
	)]
	public function showOnlineOnLogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| !is_string($sender)
		) {
			return;
		}
		$msg = $this->getOnlineList();
		$this->chatBot->sendMassTell($msg, $sender);
	}

	#[NCA\Event(
		name: "timer(10mins)",
		description: "Online check"
	)]
	public function onlineCheckEvent(Event $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		/** @var Collection<Online> */
		$data = $this->db->table("online")
			->asObj(Online::class);

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
					$this->logger->warning("Unknown channel type: '$row->channel_type'. Expected: 'guild' or 'priv'");
			}
		}

		$time = time();

		foreach ($this->chatBot->guildmembers as $name => $rank) {
			if ($this->buddylistManager->isOnline($name)) {
				if (in_array($name, $guildArray)) {
					$this->buildOnlineQuery($name, "guild")
						->update(["dt" => $time]);
				} else {
					$this->db->table("online")
						->insert([
							"name" => $name,
							"channel" => $this->db->getMyguild(),
							"channel_type" => "guild",
							"added_by" => $this->db->getBotname(),
							"dt" => $time
						]);
				}
			}
		}

		foreach ($this->chatBot->chatlist as $name => $value) {
			if (in_array($name, $privArray)) {
					$this->buildOnlineQuery($name, "priv")
						->update(["dt" => $time]);
			} else {
					$this->db->table("online")
						->insert([
							"name" => $name,
							"channel" => $this->db->getMyguild() . " Guests",
							"channel_type" => "priv",
							"added_by" => $this->db->getBotname(),
							"dt" => $time
						]);
			}
		}

		$this->db->table("online")
			->where(function(QueryBuilder $query) use ($time) {
				$query->where("dt", "<", $time)
					->where("added_by", $this->db->getBotname());
			})->orWhere("dt", "<", $time - ($this->settingManager->getInt('online_expire')??900))
			->delete();
	}

	#[
		NCA\Event(
			name: "priv",
			description: "Afk check",
			help: "afk"
		),
	]
	public function afkCheckPrivateChannelEvent(AOChatEvent $eventObj): void {
		$this->afkCheck($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	#[
		NCA\Event(
			name: "guild",
			description: "Afk check",
			help: "afk"
		),
	]
	public function afkCheckGuildChannelEvent(AOChatEvent $eventObj): void {
		$this->afkCheck($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	#[
		NCA\Event(
			name: "priv",
			description: "Sets a member afk",
			help: "afk"
		),
	]
	public function afkPrivateChannelEvent(AOChatEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$this->afk($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	#[
		NCA\Event(
			name: "guild",
			description: "Sets a member afk",
			help: "afk"
		),
	]
	public function afkGuildChannelEvent(AOChatEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$this->afk($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * Set someone back from afk if needed
	 */
	public function afkCheck(int|string $sender, string $message, string $type): void {
		// to stop raising and lowering the cloak messages from triggering afk check
		if (!is_string($sender) || !$this->util->isValidSender($sender)) {
			return;
		}

		$symbol = $this->settingManager->getString('symbol');
		if (preg_match("/^\Q$symbol\E?afk(.*)$/i", $message)) {
			return;
		}
		/** @var ?string */
		$afk = $this->buildOnlineQuery($sender, $type)
			->select("afk")
			->pluckAs("afk", "string")->first();

		if ($afk === null || $afk === '') {
			return;
		}
		$time = explode('|', $afk)[0];
		$timeString = $this->util->unixtimeToReadable(time() - (int)$time);
		// $sender is back
		$this->buildOnlineQuery($sender, $type)
			->update(["afk" => ""]);
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
		$symbolModifier = "";
		if ($this->settingManager->getBool('afk_brb_without_symbol')) {
			$symbolModifier = "?";
		}
		if (preg_match("/^\Q$symbol\E${symbolModifier}afk$/i", $message)) {
			$reason = (string)time();
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
			$msg = "<highlight>$sender<end> is now AFK.";
		} elseif (preg_match("/^\Q$symbol\E${symbolModifier}brb(.*)$/i", $message, $arr)) {
			$reason = time() . '|brb ' . trim($arr[1]);
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
		} elseif (preg_match("/^\Q$symbol\E${symbolModifier}afk[, ]+(.*)$/i", $message, $arr)) {
			$reason = time() . '|' . $arr[1];
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
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
		$exists = $this->buildOnlineQuery($sender, $channelType)->exists();
		if (!$exists) {
			$this->db->table("online")
				->insert([
					"name" => $sender,
					"channel" => $channel,
					"channel_type" => $channelType,
					"added_by" => $this->db->getBotname(),
					"dt" => time()
				]);
		}
		$op = new OnlinePlayer();
		$player = $this->playerManager->findInDb($sender, $this->config->dimension);
		if (isset($player)) {
			foreach (get_object_vars($player) as $key => $value) {
				$op->{$key} = $value;
			}
		}
		$op->online = true;
		$op->pmain = $this->altsController->getMainOf($sender);
		return $op;
	}

	public function removePlayerFromOnlineList(string $sender, string $channelType): void {
		$this->buildOnlineQuery($sender, $channelType)->delete();
	}

	/**
	 * Group the relay online list as configured
	 * @param array<string,Relay> $relays
	 * @return array<string,OnlinePlayer[]>
	 */
	protected function groupRelayList(array $relays): array {
		$groupBy = $this->settingManager->getInt('online_relay_group_by')??self::GROUP_BY_ORG;
		if ($groupBy === self::GROUP_OFF) {
			$key = 'Alliance';
		}
		$result = [];
		foreach ($this->relayController->relays as $name => $relay) {
			$this->logger->info("Getting online list for relay {relay}", [
				"relay" => $relay->getName(),
			]);
			$online = $relay->getOnlineList();
			$this->logger->info("Got {numOnline} characters online in total on {relay}", [
				"numOnline" => array_sum(array_map("count", array_values($online))),
				"relay" => $relay->getName(),
			]);
			foreach ($online as $chanName => $onlineChars) {
				$this->logger->info("{numOnline} characters online in {relay}.{channel}", [
					"relay" => $relay->getName(),
					"channel" => $chanName,
					"numOnline" => count($onlineChars),
					"characters" => $onlineChars,
				]);
				$key = "";
				if ($groupBy === self::GROUP_BY_ORG) {
					$key = $chanName;
				}
				$chars = array_values($onlineChars);
				foreach ($chars as $char) {
					if ($groupBy === self::GROUP_BY_PROFESSION) {
						$key = $char->profession ?? "Unknown";
						$profIcon = "?";
						if ($char->profession !== null) {
							$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".($this->getProfessionId($char->profession)??0).">";
						}
						$key = "{$profIcon} {$key}";
					}
					$result[$key] ??= [];
					$result[$key][$char->name] = $char;
				}
			}
		}
		foreach ($result as $key => &$chars) {
			$chars = array_values($chars);
			usort($chars, function(OnlinePlayer $a, OnlinePlayer $b): int {
				return strcasecmp($a->name, $b->name);
			});
		}
		uksort($result, function (string $a, string $b): int {
			return strcasecmp(strip_tags($a), strip_tags($b));
		});

		return $result;
	}

	/**
	 * Get a page or multiple pages with the online list
	 * @return string[]
	 */
	public function getOnlineList(int $includeRelay=null): array {
		$includeRelay ??= $this->settingManager->getInt("online_show_relay");
		$orgData = $this->getPlayers('guild');
		$orgList = $this->formatData($orgData, $this->settingManager->getInt("online_show_org_guild")??1);

		$privData = $this->getPlayers('priv');
		$privList = $this->formatData($privData, $this->settingManager->getInt("online_show_org_priv")??2);

		$relayGrouped = $this->groupRelayList($this->relayController->relays);
		/** @var array<string,OnlineList> */
		$relayList = [];
		$relayOrgInfo = $this->settingManager->getInt("online_show_org_guild_relay")??0;
		foreach ($relayGrouped as $group => $chars) {
			$relayList[$group] = $this->formatData($chars, $relayOrgInfo, static::GROUP_OFF);
		}

		$discData = [];
		if ($this->settingManager->getBool("online_show_discord")) {
			$discData = $this->discordGatewayController->getPlayersInVoiceChannels();
		}

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
		foreach ($discData as $serverName => $channels) {
			$guildCount = 0;
			$guildUsers = "";
			foreach ($channels as $channel => $users) {
				$guildUsers .= "\n<highlight>{$channel}<end>\n";
				foreach ($users as $user) {
					$guildUsers .= "<tab>{$user}\n";
					$guildCount++;
					$totalCount++;
					$totalMain++;
				}
			}
			$blob .= "<header2>{$serverName} ({$guildCount})<end>\n".
				$guildUsers;
			$blob .= "\n\n";
		}

		$blob2 = '';
		$allianceTotalCount = 0;
		$allianceTotalMain = 0;
		if ($includeRelay !== self::RELAY_OFF) {
			foreach ($relayList as $chanName => $chanList) {
				if ($chanList->count > 0) {
					$part = "<header2>{$chanName} ({$chanList->count})<end>\n".
						$chanList->blob ."\n";
					if ($includeRelay === self::RELAY_YES) {
						$blob .= $part;
					} elseif ($includeRelay === self::RELAY_SEPARATE) {
						$blob2 .= $part;
					}
					$allianceTotalCount += $chanList->count;
					$allianceTotalMain += $chanList->countMains;
				}
			}
		}
		if ($includeRelay !== self::RELAY_SEPARATE) {
			$totalCount += $allianceTotalCount;
			$totalMain += $allianceTotalMain;
		}

		$msg = [];
		if ($totalCount > 0) {
			$blob .= "Originally written by Naturarum (RK2)";
			$msg = (array)$this->text->makeBlob("Players Online ($totalMain)", $blob);
		}
		if ($allianceTotalCount > 0 && $includeRelay === self::RELAY_SEPARATE) {
			$allianceMsg = (array)$this->text->makeBlob("Players Online in alliance ($allianceTotalMain)", $blob2);
			$msg = array_merge($msg, $allianceMsg);
		}
		if (empty($msg)) {
			$msg = (array)"Players Online (0)";
		}
		return $msg;
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

	/**
	 * @return string[]
	 * @psalm-return array{0: string, 1: string}
	 * @phpstan-return array{0: string, 1: string}
	 */
	public function getRaidInfo(string $name, string $fancyColon): array {
		$mode = $this->settingManager->getInt("online_raid")??0;
		if ($mode === 0) {
			return ["", ""];
		}
		if (!isset($this->raidController->raid)) {
			return ["", ""];
		}
		$inRaid = isset($this->raidController->raid->raiders[$name])
			&& $this->raidController->raid->raiders[$name]->left === null;

		if (($mode & static::RAID_IN) && $inRaid) {
			if ($mode & static::RAID_COMPACT) {
				return ["[<green>R<end>] ", ""];
			}
			return ["", " $fancyColon <green>in raid<end>"];
		} elseif (($mode & static::RAID_NOT_IN) && !$inRaid) {
			if ($mode & static::RAID_COMPACT) {
				return ["[<red>R<end>] ", ""];
			}
			return ["", " $fancyColon <red>not in raid<end>"];
		}
		return ["", ""];
	}

	public function getAfkInfo(string $afk, string $fancyColon): string {
		if (empty($afk)) {
			return '';
		}
		$props = explode("|", $afk, 2);
		if (count($props) === 1 || !strlen($props[1])) {
			$timeString = $this->util->unixtimeToReadable(time() - (int)$props[0], false);
			return " $fancyColon <highlight>AFK for $timeString<end>";
		}
		$timeString = $this->util->unixtimeToReadable(time() - (int)$props[0], false);
		return " $fancyColon <highlight>AFK for $timeString: {$props[1]}<end>";
	}

	/**
	 * @param OnlinePlayer[] $players
	 * @param int $showOrgInfo
	 * @return OnlineList
	 */
	public function formatData(array $players, int $showOrgInfo, ?int $groupBy=null): OnlineList {
		$currentGroup = "";
		$separator = "-";
		$list = new OnlineList();
		$list->count = count($players);
		$list->countMains = 0;
		$list->blob = "";

		if ($list->count === 0) {
			return $list;
		}
		$groupBy ??= $this->settingManager->getInt('online_group_by');
		$factions = [];
		if ($groupBy === static::GROUP_BY_FACTION) {
			foreach ($players as $player) {
				$player->faction = $player->faction ?: "Neutral";
				$factions[$player->faction] ??= 0;
				$factions[$player->faction]++;
			}
		}
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
						$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".($this->getProfessionId($player->profession)??0).">";
					}
					$list->blob .= "\n<pagebreak>{$profIcon}<highlight>{$player->profession}<end>\n";
					$currentGroup = $player->profession;
				}
			} elseif ($groupBy === static::GROUP_BY_FACTION) {
				$list->countMains++;
				if ($currentGroup !== $player->faction) {
					$list->blob .= "\n<pagebreak><" . strtolower($player->faction) . ">".
						$player->faction . " (" . ($factions[$player->faction]??1) . ")<end>\n";
					$currentGroup = $player->faction;
				}
			} else {
				$list->countMains++;
			}

			$admin = $this->getAdminInfo($player->name, $separator);
			[$raidPre, $raidPost] = $this->getRaidInfo($player->name, $separator);
			$afk = $this->getAfkInfo($player->afk??"", $separator);

			if ($player->profession === null) {
				$list->blob .= "<tab>? {$raidPre}{$player->name}{$admin}{$raidPost}{$afk}\n";
			} else {
				$prof = $this->util->getProfessionAbbreviation($player->profession);
				$orgRank = "";
				if (isset($player->guild) && isset($player->guild_rank)) {
					$orgRank = $this->getOrgInfo($showOrgInfo, $separator, $player->guild, $player->guild_rank);
				}
				$profIcon = "";
				if ($groupBy !== static::GROUP_BY_PROFESSION) {
					$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".($this->getProfessionId($player->profession)??0)."> ";
				}
				$list->blob.= "<tab>{$profIcon}{$raidPre}{$player->name} - {$player->level}/<green>{$player->ai_level}<end> {$prof}{$orgRank}{$admin}{$raidPost}{$afk}\n";
			}
		}

		return $list;
	}

	/**
	 * @return OnlinePlayer[]
	 */
	public function getPlayers(string $channelType, ?string $limitToBot=null): array {
		$query = $this->db->table("online AS o")
			->where("o.channel_type", $channelType);
		if (isset($limitToBot)) {
			$query->where("o.added_by", strtolower($limitToBot));
		}
		$online = $query->asObj(Online::class);
		$playersByName = $this->playerManager->searchByNames(
			$this->config->dimension,
			...$online->pluck("name")->toArray()
		)->keyBy("name");
		$op = $online->map(function (Online $o) use ($playersByName): OnlinePlayer {
			$p = $playersByName->get($o->name);
			$op = OnlinePlayer::fromPlayer($p, $o);
			$op->pmain = $this->altsController->getMainOf($o->name);
			return $op;
		});

		$groupBy = $this->settingManager->getInt('online_group_by');
		if ($groupBy === static::GROUP_BY_MAIN) {
			$op = $op->sortBy("pmain");
		} elseif ($groupBy === static::GROUP_BY_PROFESSION) {
			$op = $op->sortBy("name")->sortBy("profession");
		} elseif ($groupBy === static::GROUP_BY_FACTION) {
			$op = $op->sortBy("name")->sortBy("faction");
		} else {
			$op = $op->sortBy("name");
		}
		return $op->toArray();
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
	 */
	#[
		NCA\Api("/online"),
		NCA\GET,
		NCA\AccessLevelFrom("online"),
		NCA\ApiResult(code: 200, class: "OnlinePlayers", desc: "A list of online players")
	]
	public function apiOnlineEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$result = new OnlinePlayers();
		$result->org = $this->getPlayers('guild');
		$result->private_channel = $this->getPlayers('priv');
		return new ApiResponse($result);
	}
}
