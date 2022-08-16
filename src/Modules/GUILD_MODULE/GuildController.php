<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use function Amp\Promise\rethrow;
use function Amp\{asyncCall, call};

use Amp\Promise;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	ConfigFile,
	DB,
	DBSchema\Player,
	Event,
	LoggerWrapper,
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
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};
use Throwable;

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Derroylo (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Base"),
	NCA\DefineCommand(
		command: "logon",
		accessLevel: "guild",
		description: "Set logon message",
	),
	NCA\DefineCommand(
		command: "logoff",
		accessLevel: "guild",
		description: "Set logoff message",
	),
	NCA\DefineCommand(
		command: "lastseen",
		accessLevel: "guild",
		description: "Shows the last logoff time of a character",
	),
	NCA\DefineCommand(
		command: "recentseen",
		accessLevel: "guild",
		description: "Shows org members who have logged off recently",
	),
	NCA\DefineCommand(
		command: "notify",
		accessLevel: "mod",
		description: "Adds a character to the notify list manually",
	),
	NCA\DefineCommand(
		command: "updateorg",
		accessLevel: "mod",
		description: "Force an update of the org roster",
	),
]
class GuildController extends ModuleInstance {
	public const DB_TABLE = "org_members_<myname>";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public Preferences $preferences;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Maximum characters a logon message can have */
	#[NCA\Setting\Number(options: [100, 200, 300, 400])]
	public int $maxLogonMsgSize = 200;

	/** Maximum characters a logoff message can have */
	#[NCA\Setting\Number(options: [100, 200, 300, 400])]
	public int $maxLogoffMsgSize = 200;

	/** Show logon/logoff for first/last alt only */
	#[NCA\Setting\Boolean]
	public bool $firstAndLastAltOnly = false;

	/** Do not show the altlist on logon, just the name of the main */
	#[NCA\Setting\Boolean]
	public bool $orgSuppressAltList = false;

	#[NCA\Setup]
	public function setup(): void {
		$this->loadGuildMembers();
	}

	/** See your current logon message */
	#[NCA\HandlesCommand("logon")]
	public function logonMessageShowCommand(CmdContext $context): void {
		$logonMessage = $this->preferences->get($context->char->name, 'logon_msg');

		if ($logonMessage === null || $logonMessage === '') {
			$msg = "Your logon message has not been set.";
		} else {
			$msg = "{$context->char->name} logon: {$logonMessage}";
		}
		$context->reply($msg);
	}

	/** Set your new logon message. 'clear' to remove it */
	#[NCA\HandlesCommand("logon")]
	public function logonMessageSetCommand(CmdContext $context, string $logonMessage): void {
		if ($logonMessage === 'clear') {
			$this->preferences->save($context->char->name, 'logon_msg', '');
			$msg = "Your logon message has been cleared.";
		} elseif (strlen($logonMessage) <= $this->maxLogonMsgSize) {
			$this->preferences->save($context->char->name, 'logon_msg', $logonMessage);
			$msg = "Your logon message has been set.";
		} else {
			$msg = "Your logon message is too large. ".
				"Your logon message may contain a maximum of ".
				"{$this->maxLogonMsgSize} characters.";
		}
		$context->reply($msg);
	}

	/** See your current logon message */
	#[NCA\HandlesCommand("logoff")]
	public function logoffMessageShowCommand(CmdContext $context): void {
		$logoffMessage = $this->preferences->get($context->char->name, 'logoff_msg');

		if ($logoffMessage === null || $logoffMessage === '') {
			$msg = "Your logoff message has not been set.";
		} else {
			$msg = "{$context->char->name} logoff: {$logoffMessage}";
		}
		$context->reply($msg);
	}

	/** Set your new logoff message. 'clear' to remove it */
	#[NCA\HandlesCommand("logoff")]
	public function logoffMessageSetCommand(CmdContext $context, string $logoffMessage): void {
		if ($logoffMessage == 'clear') {
			$this->preferences->save($context->char->name, 'logoff_msg', '');
			$msg = "Your logoff message has been cleared.";
		} elseif (strlen($logoffMessage) <= $this->maxLogoffMsgSize) {
			$this->preferences->save($context->char->name, 'logoff_msg', $logoffMessage);
			$msg = "Your logoff message has been set.";
		} else {
			$msg = "Your logoff message is too large. ".
				"Your logoff message may contain a maximum of ".
				"{$this->maxLogoffMsgSize} characters.";
		}
		$context->reply($msg);
	}

	/** Check when a member of your org was last seen online by the bot */
	#[NCA\HandlesCommand("lastseen")]
	public function lastSeenCommand(CmdContext $context, PCharacter $name): Generator {
		$uid = yield $this->chatBot->getUid2($name());
		if ($uid === null) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$altInfo = $this->altsController->getAltInfo($name());
		$onlineAlts = $altInfo->getOnlineAlts();

		$blob = "";
		foreach ($onlineAlts as $onlineAlt) {
			$blob .= "<highlight>{$onlineAlt}<end> is currently online.\n";
		}

		$alts = $altInfo->getAllAlts();

		/** @var Collection<OrgMember> */
		$data = $this->db->table(self::DB_TABLE)
			->whereIn("name", $alts)
			->where("mode", "!=", "del")
			->orderByDesc("logged_off")
			->asObj(OrgMember::class);

		foreach ($data as $row) {
			if (in_array($row->name, $onlineAlts)) {
				// skip
				continue;
			} elseif ($row->logged_off == 0) {
				$blob .= "<highlight>{$row->name}<end> has never logged on.\n";
			} else {
				$blob .= "<highlight>{$row->name}<end> last seen at " . $this->util->date($row->logged_off) . ".\n";
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
	#[NCA\HandlesCommand("recentseen")]
	#[NCA\Help\Example(
		command: "<symbol>recentseen 1d",
		description: "List all org members who logged on within 1 day"
	)]
	public function recentSeenCommand(CmdContext $context, PDuration $duration): void {
		if (!$this->isGuildBot()) {
			$context->reply("The bot must be in an org.");
			return;
		}

		$time = $duration->toSecs();
		if ($time < 1) {
			$msg = "You must enter a valid time parameter.";
			$context->reply($msg);
			return;
		}

		$timeString = $this->util->unixtimeToReadable($time, false);
		$time = time() - $time;

		/** @var Collection<RecentOrgMember> */
		$members = $this->db->table(self::DB_TABLE)
			->where("mode", "!=", "del")
			->where("logged_off", ">", $time)
			->orderByDesc("logged_off")
			->asObj(RecentOrgMember::class);
		$members->each(function (RecentOrgMember $member): void {
			$member->main = $this->altsController->getMainOf($member->name);
		});

		if ($members->count() === 0) {
			$context->reply("No members recorded.");
			return;
		}
		$members = $members->groupBy("main");

		$numRecentCount = 0;
		$highlight = false;

		$blob = "Org members who have logged off within the last <highlight>{$timeString}<end>.\n\n";

		$prevToon = '';
		foreach ($members as $main => $memberAlts) {
			/** @var Collection<RecentOrgMember> $memberAlts */
			$member = $memberAlts->first();

			/** @var RecentOrgMember $member */
			if ($member->main === $prevToon) {
				continue;
			}
			$prevToon = $member->main;
			$numRecentCount++;
			$alts = $this->text->makeChatcmd("alts", "/tell <myname> alts {$member->main}");
			$logged = $member->logged_off??time();
			$lastToon = $member->name;

			$character = "<pagebreak><highlight>{$member->main}<end> [{$alts}]\n".
				"<tab>Last seen as {$lastToon} on ".
				$this->util->date($logged) . "\n\n";
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
	#[NCA\HandlesCommand("notify")]
	public function notifyAddCommand(
		CmdContext $context,
		#[NCA\Str("on", "add")] string $action,
		PCharacter $char
	): Generator {
		$name = $char();
		$uid = yield $this->chatBot->getUid2($name);

		if ($uid === null) {
			$msg = "<highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$mode = $this->db->table(self::DB_TABLE)
			->where("name", $name)
			->select("mode")
			->pluckStrings("mode")->first();

		if ($mode !== null && $mode !== "del") {
			$msg = "<highlight>{$name}<end> is already on the Notify list.";
			$context->reply($msg);
			return;
		}
		$this->db->table(self::DB_TABLE)
			->upsert(["name" => $name, "mode" => "add"], "name");

		if ($this->buddylistManager->isOnline($name)) {
			$this->db->table("online")
				->insert([
					"name" => $name,
					"channel" => $this->db->getMyguild(),
					"channel_type" => "guild",
					"added_by" => $this->db->getBotname(),
					"dt" => time(),
				]);
		}
		yield $this->buddylistManager->addAsync($name, 'org');
		$this->chatBot->guildmembers[$name] = 6;
		$msg = "<highlight>{$name}<end> has been added to the Notify list.";

		$context->reply($msg);
	}

	/** Manually remove a character from the notify list */
	#[NCA\HandlesCommand("notify")]
	public function notifyRemoveCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $char
	): Generator {
		$name = $char();
		$uid = yield $this->chatBot->getUid2($name);

		if ($uid === null) {
			$msg = "<highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$mode = $this->db->table(self::DB_TABLE)
			->where("name", $name)
			->select("mode")
			->pluckStrings("mode")->first();

		if ($mode === null) {
			$msg = "<highlight>{$name}<end> is not on the guild roster.";
		} elseif ($mode == "del") {
			$msg = "<highlight>{$name}<end> has already been removed from the Notify list.";
		} else {
			$this->db->table(self::DB_TABLE)
				->where("name", $name)
				->update(["mode" => "del"]);
			$this->delMemberFromOnline($name);
			$this->buddylistManager->remove($name, 'org');
			unset($this->chatBot->guildmembers[$name]);
			$msg = "Removed <highlight>{$name}<end> from the Notify list.";
		}

		$context->reply($msg);
	}

	/** Force an update of the org roster */
	#[NCA\HandlesCommand("updateorg")]
	public function updateorgCommand(CmdContext $context): Generator {
		$context->reply("Starting Roster update");
		try {
			yield $this->updateMyOrgRoster();
		} catch (Throwable $e) {
			$context->reply("There was an error during the roster update: ".
				$e->getMessage());
			return;
		}
		$context->reply("Finished Roster update");
	}

	/** @deprecated */
	public function updateOrgRoster(?callable $callback=null, mixed ...$args): void {
		asyncCall(function () use ($callback, $args): Generator {
			yield $this->updateMyOrgRoster();
			if (isset($callback)) {
				$callback(...$args);
			}
		});
	}

	/** @return Promise<void> */
	public function updateMyOrgRoster(): Promise {
		return call(function (): Generator {
			if (!$this->isGuildBot() || !isset($this->config->orgId)) {
				return;
			}
			$this->logger->notice("Starting Roster update");
			$org = yield $this->guildManager->byId($this->config->orgId, $this->config->dimension, false);
			yield $this->updateRosterForGuild($org);
		});
	}

	public function dispatchRoutableEvent(Base $event): void {
		$re = new RoutableEvent();
		$re->type = RoutableEvent::TYPE_EVENT;
		$abbr = $this->settingManager->getString('relay_guild_abbreviation');
		$re->prependPath(new Source(
			Source::ORG,
			$this->config->orgName,
			($abbr === "none") ? null : $abbr
		));
		$re->setData($event);
		$this->messageHub->handle($re);
	}

	#[NCA\Event(
		name: "timer(24hrs)",
		description: "Download guild roster xml and update guild members"
	)]
	public function downloadOrgRosterEvent(Event $eventObj): Generator {
		yield $this->updateMyOrgRoster();
	}

	#[NCA\Event(
		name: "orgmsg",
		description: "Automatically update guild roster as characters join and leave the guild"
	)]
	public function autoNotifyOrgMembersEvent(AOChatEvent $eventObj): Generator {
		$message = $eventObj->message;
		if (preg_match("/^(.+) invited (.+) to your organization.$/", $message, $arr)) {
			$name = ucfirst(strtolower($arr[2]));

			if (
				$this->buddylistManager->isOnline($name)
				&& $this->db->table("online")
					->where("name", $name)
					->where("channel_type", "guild")
					->where("added_by", $this->db->getBotname())
					->doesntExist()
			) {
				$this->db->table("online")
					->insert([
						"name" => $name,
						"channel" => $this->db->getMyguild(),
						"channel_type" => "guild",
						"added_by" => $this->db->getBotname(),
						"dt" => time(),
					]);
			}
			$this->db->table(self::DB_TABLE)
				->upsert(["mode" => "add", "name" => $name], "name");
			yield $this->buddylistManager->addAsync($name, 'org');
			$this->chatBot->guildmembers[$name] = 6;

			// update character info
			yield $this->playerManager->byName($name);
		} elseif (
			preg_match("/^(.+) kicked (?<char>.+) from your organization.$/", $message, $arr)
			|| preg_match("/^(.+) removed inactive character (?<char>.+) from your organization.$/", $message, $arr)
			|| preg_match("/^(?<char>.+) just left your organization.$/", $message, $arr)
			|| preg_match("/^(?<char>.+) kicked from organization \\(alignment changed\\).$/", $message, $arr)
		) {
			$name = ucfirst(strtolower($arr["char"]));

			$this->db->table(self::DB_TABLE)
				->where("name", $name)
				->update(["mode" => "del"]);
			$this->delMemberFromOnline($name);

			unset($this->chatBot->guildmembers[$name]);
			$this->buddylistManager->remove($name, 'org');
		}
	}

	/**
	 * @phpstan-param callable(string):mixed $callback
	 *
	 * @deprecated
	 */
	public function getLogonForPlayer(callable $callback, ?Player $whois, string $player, bool $suppressAltList): void {
		asyncCall(function () use ($callback, $whois, $player, $suppressAltList): Generator {
			$callback(yield $this->getLogonMessageForPlayer($whois, $player, $suppressAltList));
		});
	}

	/**
	 * @phpstan-param callable(string):mixed $callback
	 *
	 * @deprecated
	 */
	public function getLogonMessageAsync(string $player, bool $suppressAltList, callable $callback): void {
		asyncCall(function () use ($player, $suppressAltList, $callback): Generator {
			$msg = yield $this->getLogonMessage($player, $suppressAltList);
			if (isset($msg)) {
				$callback($msg);
			}
		});
	}

	/** @return Promise<?string> */
	public function getLogonMessage(string $player, bool $suppressAltList): Promise {
		return call(function () use ($player, $suppressAltList): Generator {
			if ($this->firstAndLastAltOnly) {
				// if at least one alt/main is already online, don't show logon message
				$altInfo = $this->altsController->getAltInfo($player);
				if (count($altInfo->getOnlineAlts()) > 1) {
					return null;
				}
			}

			$whois = yield $this->playerManager->byName($player);
			return yield $this->getLogonMessageForPlayer($whois, $player, $suppressAltList);
		});
	}

	/** @return Promise<string> */
	public function getLogonMessageForPlayer(?Player $whois, string $player, bool $suppressAltList): Promise {
		return call(function () use ($whois, $player, $suppressAltList): Generator {
			$msg = '';
			$logonMsg = $this->preferences->get($player, 'logon_msg') ?? "";
			if ($logonMsg !== '') {
				$logonMsg = " - {$logonMsg}";
			}
			if ($whois === null) {
				$msg = "{$player} logged on";
			} else {
				$msg = $this->playerManager->getInfo($whois);

				$msg .= " logged on";

				$altInfo = $this->altsController->getAltInfo($player);
				if ($suppressAltList) {
					if ($altInfo->main !== $player) {
						$msg .= ". Alt of <highlight>{$altInfo->main}<end>";
					}
				} else {
					if (count($altInfo->getAllValidatedAlts()) > 0) {
						$blob = yield $altInfo->getAltsBlob(true);
						$blob = ((array)$blob)[0];
						return "{$msg}. {$blob}{$logonMsg}";
					}
				}
			}

			return $msg.$logonMsg;
		});
	}

	#[NCA\Event(
		name: "logOn",
		description: "Shows an org member logon in chat"
	)]
	public function orgMemberLogonMessageEvent(UserStateEvent $eventObj): Generator {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| !is_string($sender)) {
			return;
		}
		$msg = yield $this->getLogonMessage($sender, $this->orgSuppressAltList);
		$uid = yield $this->chatBot->getUid2($sender);
		$e = new Online();
		$e->char = new Character($sender, $uid);
		$e->main = $this->altsController->getMainOf($sender);
		$e->online = true;
		$e->message = $msg;
		$this->dispatchRoutableEvent($e);
		if (isset($msg)) {
			$this->chatBot->sendGuild($msg, true);
		}
	}

	public function getLogoffMessage(string $player): ?string {
		if ($this->firstAndLastAltOnly) {
			// if at least one alt/main is still online, don't show logoff message
			$altInfo = $this->altsController->getAltInfo($player);
			if (count($altInfo->getOnlineAlts()) > 0) {
				return null;
			}
		}

		$msg = "{$player} logged off.";
		$logoffMessage = $this->preferences->get($player, 'logoff_msg');
		if ($logoffMessage !== null && $logoffMessage !== '') {
			$msg .= " " . $logoffMessage;
		}
		return $msg;
	}

	#[NCA\Event(
		name: "logOff",
		description: "Shows an org member logoff in chat"
	)]
	public function orgMemberLogoffMessageEvent(UserStateEvent $eventObj): Generator {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| !is_string($sender)) {
			return;
		}

		$uid = yield $this->chatBot->getUid2($sender);
		$msg = $this->getLogoffMessage($sender);
		$e = new Online();
		$e->char = new Character($sender, $uid);
		$e->main = $this->altsController->getMainOf($sender);
		$e->online = false;
		$e->message = $msg;
		$this->dispatchRoutableEvent($e);
		if ($msg === null) {
			return;
		}

		$this->chatBot->sendGuild($msg, true);
	}

	#[NCA\Event(
		name: "logOff",
		description: "Record org member logoff for lastseen command"
	)]
	public function orgMemberLogoffRecordEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| !is_string($sender)
		) {
			return;
		}
		$this->db->table(self::DB_TABLE)
			->where("name", $sender)
			->update(["logged_off" => time()]);
	}

	public function isGuildBot(): bool {
		return !empty($this->config->orgName)
			&& !empty($this->config->orgId);
	}

	#[NCA\Event(
		name: "connect",
		description: "Verifies that org name is correct"
	)]
	public function verifyOrgNameEvent(Event $eventObj): void {
		if (empty($this->config->orgName)) {
			return;
		}
		if (empty($this->config->orgId)) {
			$this->logger->warning("Org name '{$this->config->orgName}' specified, but bot does not appear to belong to an org");
			return;
		}
		$gid = $this->getOrgChannelIdByOrgId($this->config->orgId);
		$orgChannel = $this->chatBot->gid[$gid]??null;
		if (isset($orgChannel) && $orgChannel !== "Clan (name unknown)" && $orgChannel !== $this->config->orgName) {
			$this->logger->warning("Org name '{$this->config->orgName}' specified, but bot belongs to org '{$orgChannel}'");
		}
	}

	public function getOrgChannelIdByOrgId(int $orgId): ?string {
		foreach ($this->chatBot->grp as $gid => $status) {
			$string = \Safe\unpack("N", substr((string)$gid, 1));
			if (ord(substr((string)$gid, 0, 1)) === 3 && $string[1] == $orgId) {
				return (string)$gid;
			}
		}
		return null;
	}

	/** Remove someone from the online list that we added for "guild" */
	protected function delMemberFromOnline(string $member): int {
		return $this->db->table("online")
			->where("name", $member)
			->where("channel_type", "guild")
			->where("added_by", $this->db->getBotname())
			->delete();
	}

	private function loadGuildMembers(): void {
		$this->chatBot->guildmembers = [];
		$members = $this->db->table(self::DB_TABLE)
			->where("mode", "!=", "del")
			->orderBy("name")
			->asObj(OrgMember::class);
		$players = $this->playerManager
			->searchByNames($this->db->getDim(), ...$members->pluck("name")->toArray());
		$players->each(function (Player $player): void {
			$this->chatBot->guildmembers[$player->name] = $player->guild_rank_id ?? 6;
		});
	}

	/** @return Promise<void> */
	private function updateRosterForGuild(?Guild $org): Promise {
		return call(function () use ($org): Generator {
			// Check if guild xml file is correct if not abort
			if ($org === null) {
				$this->logger->error("Error downloading the guild roster xml file");
				return;
			}

			if (count($org->members) === 0) {
				$this->logger->error("Guild xml file has no members! Aborting roster update.");
				return;
			}
			$dbEntries = [];

			// Save the current org_members table in a var
			/** @var Collection<OrgMember> */
			$data = $this->db->table(self::DB_TABLE)->asObj(OrgMember::class);
			// @phpstan-ignore-next-line
			if ($data->count() === 0 && (count($org->members) > 0)) {
				$restart = true;
			} else {
				$restart = false;
				foreach ($data as $row) {
					$dbEntries[$row->name] = [
						"name" => $row->name,
						"mode" => $row->mode,
					];
				}
			}

			yield $this->db->awaitBeginTransaction();

			$this->chatBot->ready = false;

			// Going through each member of the org and add or update his/her
			foreach ($org->members as $member) {
				// don't do anything if $member is the bot itself
				if (strtolower($member->name) === strtolower($this->chatBot->char->name)) {
					continue;
				}

				// If there exists already data about the character just update him/her
				if (isset($dbEntries[$member->name])) {
					if ($dbEntries[$member->name]["mode"] === "del") {
						// members who are not on notify should not be on the buddy list but should remain in the database
						$this->buddylistManager->remove($member->name, 'org');
						unset($this->chatBot->guildmembers[$member->name]);
					} else {
						// add org members who are on notify to buddy list
						rethrow($this->buddylistManager->addAsync($member->name, 'org'));
						$this->chatBot->guildmembers[$member->name] = $member->guild_rank_id ?? 0;

						// if member was added to notify list manually, switch mode to org and let guild roster update from now on
						if ($dbEntries[$member->name]["mode"] == "add") {
							$this->db->table(self::DB_TABLE)
								->where("name", $member->name)
								->update(["mode" => "org"]);
						}
					}
				// else insert his/her data
				} else {
					// add new org members to buddy list
					rethrow($this->buddylistManager->addAsync($member->name, 'org'));
					$this->chatBot->guildmembers[$member->name] = $member->guild_rank_id ?? 0;

					$this->db->table(self::DB_TABLE)
						->insert([
							"name" => $member->name,
							"mode" => "org",
						]);
				}
				unset($dbEntries[$member->name]);
			}

			$this->db->commit();

			// remove buddies who are no longer org members
			foreach ($dbEntries as $buddy) {
				if ($buddy['mode'] !== 'add') {
					$this->delMemberFromOnline($buddy["name"]);
					$this->db->table(self::DB_TABLE)
						->where("name", $buddy["name"])
						->delete();
					$this->buddylistManager->remove($buddy['name'], 'org');
					unset($this->chatBot->guildmembers[$buddy['name']]);
				}
			}

			$this->logger->notice("Finished Roster update");
			$this->chatBot->setupReadinessTimer();

			if ($restart === true) {
				$this->loadGuildMembers();
			}
		});
	}
}
