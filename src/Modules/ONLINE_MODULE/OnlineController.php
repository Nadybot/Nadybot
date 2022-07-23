<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AOChatEvent,
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	ConfigFile,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	ModuleInstance,
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
	RAID_MODULE\RaidRankController,
	RELAY_MODULE\Relay,
	RELAY_MODULE\RelayController,
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
	NCA\DefineCommand(
		command: OnlineController::CMD_MANAGE_HIDDEN,
		accessLevel: "mod",
		description: "Manage hidden characters from the online list",
	),

	NCA\ProvidesEvent("online(org)"),
	NCA\ProvidesEvent("offline(org)")
]
class OnlineController extends ModuleInstance {
	public const CMD_MANAGE_HIDDEN = "online manage hidden users";

	public const DB_TABLE_HIDE = "online_hide_<myname>";
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
	public RaidRankController $raidRankController;

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

	/** How long to wait before clearing online list */
	#[NCA\Setting\Time(options: ["2m", "5m", "10m", "15m", "20m"])]
	public int $onlineExpire = 15*60;

	/** Include players from your relay(s) by default */
	#[NCA\Setting\Options(options: [
		'No' => 0,
		'Always' => 1,
		'In a separate message' => 2,
	])]
	public int $onlineShowRelay = 0;

	/** Show org/rank for players in guild channel */
	#[NCA\Setting\Options(options: [
		'Show org and rank' => 2,
		'Show rank only' => 1,
		'Show org only' => 3,
		'Show no org info' => 0,
	])]
	public int $onlineShowOrgGuild = 1;

	/** Show org/rank for players in your relays */
	#[NCA\Setting\Options(options: [
		'Show org and rank' => 2,
		'Show rank only' => 1,
		'Show org only' => 3,
		'Show no org info' => 0,
	])]
	public int $onlineShowOrgGuildRelay = 0;

	/** Show org/rank for players in private channel */
	#[NCA\Setting\Options(options: [
		'Show org and rank' => 2,
		'Show rank only' => 1,
		'Show org only' => 3,
		'Show no org info' => 0,
	])]
	public int $onlineShowOrgPriv = 2;

	/** Show admin levels in online list */
	#[NCA\Setting\Boolean]
	public bool $onlineAdmin = false;

	/** Show raid participation in online list */
	#[NCA\Setting\Options(options: [
		'off' => 0,
		'in raid' => 1,
		'not in raid' => 2,
		'both' => 3,
		'both, but compact' => 7,
	])]
	public int $onlineRaid = 0;

	/** Group online list by */
	#[NCA\Setting\Options(options: [
		'do not group' => 0,
		'player' => 1,
		'profession' => 2,
		'faction' => 3,
	])]
	public int $onlineGroupBy = 1;

	/** Group relay online list by */
	#[NCA\Setting\Options(options: [
		'do not group' => 0,
		'org' => 1,
		'profession' => 2,
	])]
	public int $onlineRelayGroupBy = 1;

	/** Show players in discord voice channels */
	#[NCA\Setting\Boolean]
	public bool $onlineShowDiscord = false;

	/** React to afk and brb even without command prefix */
	#[NCA\Setting\Boolean]
	public bool $afkBrbWithoutSymbol = true;

	/** Rank color for superadmin */
	#[NCA\Setting\Color]
	public string $rankColorSuperadmin = "#FF0000";

	/** Rank color for admin */
	#[NCA\Setting\Color]
	public string $rankColorAdmin = "#FF0000";

	/** Rank color for mod */
	#[NCA\Setting\Color]
	public string $rankColorMod = "#00DE42";

	/** Rank color for rl */
	#[NCA\Setting\Color]
	public string $rankColorRL = "#FCA712";

	/** Rank color for raid leaders/admins */
	#[NCA\Setting\Color]
	public string $rankColorRaid = "#FCA712";

	#[NCA\Setup]
	public function setup(): void {
		$this->db->table("online")
			->where("added_by", $this->db->getBotname())
			->delete();

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

	/** Show a list of hidden characters */
	#[NCA\HandlesCommand(self::CMD_MANAGE_HIDDEN)]
	public function onlineShowHiddenCommand(
		CmdContext $context,
		#[NCA\Str("hidden", "hide")] string $action
	): void {
		/** @var Collection<OnlineHide> */
		$masks = new Collection($this->getHiddenPlayerMasks());
		$masks = $masks->sortBy("mask");
		$blobs = new Collection();
		foreach ($masks as $mask) {
			$delLink = $this->text->makeChatcmd("remove", "/tell <myname> online hide del {$mask->id}");
			$dateAdded = $mask->created_on->format("d-M-Y");
			$blob = "<tab><highlight>{$mask->mask}<end> ".
				"(added by {$mask->created_by} on {$dateAdded})".
				" [{$delLink}]";
			$blobs->push($blob);
		}
		if ($blobs->isEmpty()) {
			$context->reply("Currently, no characters are hidden.");
			return;
		}
		$blob = "<header2>Hidden characters<end>\n".
			$blobs->join("\n");
		$context->reply(
			$this->text->makeBlob(
				"Hidden characters (" . $blobs->count() . ")",
				$blob
			)
		);
	}

	/**
	 * Add a character/character mask to the list of hidden characters
	 * You can use * as a wildcard that matches anything and the dot (.)
	 * if you only want to match a character in a specific org/private chat.
	 */
	#[NCA\HandlesCommand(self::CMD_MANAGE_HIDDEN)]
	#[NCA\Help\Example("<symbol>online hide nady")]
	#[NCA\Help\Example("<symbol>online hide nady*", "Hide all characters starting with nady")]
	#[NCA\Help\Example("<symbol>online hide troet.nady", "Hide Nady when he's on Troet")]
	#[NCA\Help\Example("<symbol>online hide nbt guest.*", "Hide the whole NBT Guest channel")]
	public function onlineAddHiddenCommand(
		CmdContext $context,
		#[NCA\Str("hide")] string $action,
		string $mask
	): void {
		$mask = strtolower($mask);

		/** @var Collection<OnlineHide> */
		$masks = new Collection($this->getHiddenPlayerMasks());
		if ($masks->where("mask", $mask)->isNotEmpty()) {
			$context->reply("The mask <highlight>{$mask}<end> is already hidden.");
			return;
		}
		if (strlen($mask) > 100) {
			$context->reply("The mask <highlight>{$mask}<end> is too long.");
			return;
		}
		$hidden = new OnlineHide();
		$hidden->mask = $mask;
		$hidden->created_by = $context->char->name;
		$this->db->insert(self::DB_TABLE_HIDE, $hidden);
		$context->reply("<highlight>{$mask}<end> added to the online hidden mask list.");
	}

	/** Remove a character/character mask from the list of hidden characters */
	#[NCA\HandlesCommand(self::CMD_MANAGE_HIDDEN)]
	public function onlineDelHiddenByIDCommand(
		CmdContext $context,
		#[NCA\Str("show", "unhide")] string $action,
		int $id
	): void {
		if ($this->db->table(self::DB_TABLE_HIDE)->delete($id) === 0) {
			$context->reply("The mask <highlight>#{$id}<end> is not hidden.");
		} else {
			$context->reply("<highlight>#{$id}<end> removed from the online hidden mask list.");
		}
	}

	/** Remove a character/character mask from the list of hidden characters */
	#[NCA\HandlesCommand(self::CMD_MANAGE_HIDDEN)]
	public function onlineDelHiddenCommand(
		CmdContext $context,
		#[NCA\Str("show", "unhide")] string $action,
		string $mask
	): void {
		$mask = strtolower($mask);
		if ($this->db->table(self::DB_TABLE_HIDE)
			->where("mask", $mask)
			->delete() === 0
		) {
			$context->reply("The mask <highlight>{$mask}<end> is not hidden.");
		} else {
			$context->reply("<highlight>{$mask}<end> removed from the online hidden mask list.");
		}
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
			$msg = "{$profession} Search Results (0)";
			$context->reply($msg);
			return;
		}
		foreach ($players as $player) {
			if ($currentMain !== $player->pmain) {
				$mainCount++;
				$blob .= "\n<highlight>{$player->pmain}<end> has\n";
				$currentMain = $player->pmain;
			}

			$playerName = $player->name;
			if ($player->online) {
				$playerName = $this->text->makeChatcmd($player->name, "/tell {$player->name}");
			}
			if ($player->profession === null) {
				$blob .= "<tab>({$playerName})\n";
			} else {
				$prof = $this->util->getProfessionAbbreviation($player->profession);
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".($this->getProfessionId($player->profession)??0).">";
				$blob.= "<tab>{$profIcon} {$playerName} - {$player->level}/<green>{$player->ai_level}<end> {$prof}";
			}
			if ($player->online) {
				$blob .= " <on>Online<end>";
			}
			$blob .= "\n";
		}
		$blob .= "\nWritten by Naturarum (RK2)";
		$msg = $this->text->makeBlob("{$profession} Search Results ({$mainCount})", $blob);

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
					$this->logger->warning("Unknown channel type: '{$row->channel_type}'. Expected: 'guild' or 'priv'");
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
							"dt" => $time,
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
							"dt" => $time,
						]);
			}
		}

		$this->db->table("online")
			->where(function (QueryBuilder $query) use ($time) {
				$query->where("dt", "<", $time)
					->where("added_by", $this->db->getBotname());
			})->orWhere("dt", "<", $time - $this->onlineExpire)
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

	/** Set someone back from afk if needed */
	public function afkCheck(int|string $sender, string $message, string $type): void {
		// to stop raising and lowering the cloak messages from triggering afk check
		if (!is_string($sender) || !$this->util->isValidSender($sender)) {
			return;
		}

		$symbol = $this->settingManager->getString('symbol');
		if (preg_match("/^\Q{$symbol}\E?afk(.*)$/i", $message)) {
			return;
		}

		/** @var ?string */
		$afk = $this->buildOnlineQuery($sender, $type)
			->select("afk")
			->pluckStrings("afk")->first();

		if ($afk === null || $afk === '') {
			return;
		}
		$time = explode('|', $afk)[0];
		$timeString = $this->util->unixtimeToReadable(time() - (int)$time);
		// $sender is back
		$this->buildOnlineQuery($sender, $type)
			->update(["afk" => ""]);
		$msg = "<highlight>{$sender}<end> is back after {$timeString}.";

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
		if ($this->afkBrbWithoutSymbol) {
			$symbolModifier = "?";
		}
		if (preg_match("/^\Q{$symbol}\E{$symbolModifier}afk$/i", $message)) {
			$reason = (string)time();
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
			$msg = "<highlight>{$sender}<end> is now AFK.";
		} elseif (preg_match("/^\Q{$symbol}\E{$symbolModifier}brb(.*)$/i", $message, $arr)) {
			$reason = time() . '|brb ' . trim($arr[1]);
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
		} elseif (preg_match("/^\Q{$symbol}\E{$symbolModifier}afk[, ]+(.*)$/i", $message, $arr)) {
			$reason = time() . '|' . $arr[1];
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
			$msg = "<highlight>{$sender}<end> is now AFK.";
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
					"dt" => time(),
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

	/** @return OnlineHide[] */
	public function getHiddenPlayerMasks(): array {
		return $this->db->table(self::DB_TABLE_HIDE)
			->asObj(OnlineHide::class)
			->toArray();
	}

	/**
	 * Remove all hidden characters from the list
	 *
	 * @param OnlinePlayer[] $characters
	 *
	 * @return OnlinePlayer[]
	 */
	public function filterHiddenCharacters(array $characters, string $group): array {
		$hiddenMasks = $this->getHiddenPlayerMasks();
		$visibleCharacters = [];
		foreach ($characters as $char) {
			if (!$this->charOnHiddenList($group, $char, $hiddenMasks)) {
				$visibleCharacters []= $char;
			}
		}
		return $visibleCharacters;
	}

	/**
	 * Get a page or multiple pages with the online list
	 *
	 * @return string[]
	 */
	public function getOnlineList(?int $includeRelay=null): array {
		$includeRelay ??= $this->onlineShowRelay;
		$orgData = $this->filterHiddenCharacters($this->getPlayers('guild'), $this->config->orgName);
		$orgList = $this->formatData($orgData, $this->onlineShowOrgGuild);

		$privData = $this->filterHiddenCharacters($this->getPlayers('priv'), $this->config->name);
		$privList = $this->formatData($privData, $this->onlineShowOrgPriv);

		$relayGrouped = $this->groupRelayList($this->relayController->relays);

		/** @var array<string,OnlineList> */
		$relayList = [];
		$relayOrgInfo = $this->onlineShowOrgGuildRelay;
		foreach ($relayGrouped as $group => $chars) {
			$chars = $this->filterHiddenCharacters($chars, $group);
			$relayList[$group] = $this->formatData($chars, $relayOrgInfo, static::GROUP_OFF);
		}

		$discData = [];
		if ($this->onlineShowDiscord) {
			$discData = $this->discordGatewayController->getPlayersInVoiceChannels();
		}

		$totalCount = $orgList->count + $privList->count;
		$totalMain = $orgList->countMains + $privList->countMains;

		$blob = "\n";
		if ($orgList->count > 0) {
			$blob .= "<header2>Org Channel ({$orgList->countMains})<end>\n";
			$blob .= $orgList->blob;
			$blob .= "\n\n";
		}
		if ($privList->count > 0) {
			$blob .= "<header2>Private Channel ({$privList->countMains})<end>\n";
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
			$msg = (array)$this->text->makeBlob("Players Online ({$totalMain})", $blob);
		}
		if ($allianceTotalCount > 0 && $includeRelay === self::RELAY_SEPARATE) {
			$allianceMsg = (array)$this->text->makeBlob("Players Online in alliance ({$allianceTotalMain})", $blob2);
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
				return $guild !== "" ? " {$fancyColon} {$guild}" : " {$fancyColon} Not in an org";
			case 2:
				return $guild !== "" ? " {$fancyColon} {$guild} ({$guild_rank})" : " {$fancyColon} Not in an org";
			case 1:
				return $guild !== "" ? " {$fancyColon} {$guild_rank}" : "";
			default:
				return "";
		}
	}

	public function getAdminInfo(string $name, string $fancyColon): string {
		if (!$this->onlineAdmin) {
			return "";
		}

		$accessLevel = $this->accessManager->getAccessLevelForCharacter($name);
		$displayName = ucfirst($this->accessManager->getDisplayName($accessLevel));
		switch ($accessLevel) {
			case 'superadmin':
				return " {$fancyColon} {$this->rankColorSuperadmin}{$displayName}<end>";
			case 'admin':
				return " {$fancyColon} {$this->rankColorAdmin}{$displayName}<end>";
			case 'mod':
				return " {$fancyColon} {$this->rankColorMod}{$displayName}<end>";
			case 'rl':
				return " {$fancyColon} {$this->rankColorRL}{$displayName}<end>";
		}
		$raidRank = $this->raidRankController->getSingleAccessLevel($name);
		if (isset($raidRank)) {
			$displayName = ucfirst($this->accessManager->getDisplayName($raidRank));
			return " {$fancyColon} {$this->rankColorRaid}{$displayName}<end>";
		}
		return "";
	}

	/**
	 * @return string[]
	 * @psalm-return array{0: string, 1: string}
	 * @phpstan-return array{0: string, 1: string}
	 */
	public function getRaidInfo(string $name, string $fancyColon): array {
		$mode = $this->onlineRaid;
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
				return ["[<on>R<end>] ", ""];
			}
			return ["", " {$fancyColon} <on>in raid<end>"];
		} elseif (($mode & static::RAID_NOT_IN) && !$inRaid) {
			if ($mode & static::RAID_COMPACT) {
				return ["[<off>R<end>] ", ""];
			}
			return ["", " {$fancyColon} <off>not in raid<end>"];
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
			return " {$fancyColon} <highlight>AFK for {$timeString}<end>";
		}
		$timeString = $this->util->unixtimeToReadable(time() - (int)$props[0], false);
		return " {$fancyColon} <highlight>AFK for {$timeString}: {$props[1]}<end>";
	}

	/** @param OnlinePlayer[] $players */
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
		$groupBy ??= $this->onlineGroupBy;
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
					$list->blob .= "\n<pagebreak><highlight>{$player->pmain}<end> on\n";
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
				if (isset($player->guild, $player->guild_rank)) {
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

	/** @return OnlinePlayer[] */
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

		$groupBy = $this->onlineGroupBy;
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

	/** Get a list of all people online in all linked channels */
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

	/**
	 * Group the relay online list as configured
	 *
	 * @param array<string,Relay> $relays
	 *
	 * @return array<string,OnlinePlayer[]>
	 */
	protected function groupRelayList(array $relays): array {
		$groupBy = $this->onlineRelayGroupBy;
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
			usort($chars, function (OnlinePlayer $a, OnlinePlayer $b): int {
				return strcasecmp($a->name, $b->name);
			});
		}
		uksort($result, function (string $a, string $b): int {
			return strcasecmp(strip_tags($a), strip_tags($b));
		});

		return $result;
	}

	/** @param OnlineHide[] $hiddenMasks */
	private function charOnHiddenList(string $group, OnlinePlayer $char, array $hiddenMasks): bool {
		foreach ($hiddenMasks as $mask) {
			$fullName = $char->name;
			if (str_contains($mask->mask, ".") && isset($char->guild)) {
				$fullName = "{$group}.{$fullName}";
			}
			$matches = fnmatch($mask->mask, $fullName, FNM_CASEFOLD);
			$this->logger->info("Checking mask {mask} against {fullName}: {result}", [
				"mask" => $mask->mask,
				"fullName" => $fullName,
				"result" => $matches ? "match" : "no match",
			]);
			if ($matches) {
				return true;
			}
		}
		return false;
	}
}
