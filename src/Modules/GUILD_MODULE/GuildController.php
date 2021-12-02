<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AOChatEvent,
	BuddylistManager,
	CmdContext,
	DB,
	DBRow,
	Event,
	LoggerWrapper,
	MessageHub,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PDuration;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\Events\Base;
use Nadybot\Core\Routing\Events\Online;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Derroylo (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = "logon",
 *		accessLevel = "guild",
 *		description = "Set logon message",
 *		help        = "logon_msg.txt"
 *	)
 *	@DefineCommand(
 *		command     = "logoff",
 *		accessLevel = "guild",
 *		description = "Set logoff message",
 *		help        = "logoff_msg.txt"
 *	)
 *	@DefineCommand(
 *		command     = "lastseen",
 *		accessLevel = "guild",
 *		description = "Shows the last logoff time of a character",
 *		help        = "lastseen.txt"
 *	)
 *	@DefineCommand(
 *		command     = "recentseen",
 *		accessLevel = "guild",
 *		description = "Shows org members who have logged off recently",
 *		help        = "recentseen.txt"
 *	)
 *	@DefineCommand(
 *		command     = "notify",
 *		accessLevel = "mod",
 *		description = "Adds a character to the notify list manually",
 *		help        = "notify.txt"
 *	)
 *	@DefineCommand(
 *		command     = "updateorg",
 *		accessLevel = "mod",
 *		description = "Force an update of the org roster",
 *		help        = "updateorg.txt"
 *	)
 */
class GuildController {

	public const DB_TABLE = "org_members_<myname>";

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
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public GuildManager $guildManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public Preferences $preferences;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Base");

		$this->settingManager->add(
			$this->moduleName,
			"max_logon_msg_size",
			"Maximum characters a logon message can have",
			"edit",
			"number",
			"200",
			"100;200;300;400",
			'',
			"mod"
		);
		$this->settingManager->add(
			$this->moduleName,
			"max_logoff_msg_size",
			"Maximum characters a logoff message can have",
			"edit",
			"number",
			"200",
			"100;200;300;400",
			'',
			"mod"
		);
		$this->settingManager->add(
			$this->moduleName,
			"first_and_last_alt_only",
			"Show logon/logoff for first/last alt only",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"map_org_ranks_to_bot_ranks",
			"Map org ranks to bot ranks",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"org_suppress_alt_list",
			"Do not show the altlist on logon, just the name of the main",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->loadGuildMembers();
	}

	protected function loadGuildMembers(): void {
		$this->chatBot->guildmembers = [];
		$query = $this->db->table(self::DB_TABLE . " AS o")
			->leftJoin("players AS p", function(JoinClause $join) {
				$join->on("o.name", "p.name")
					->where("p.dimension", $this->db->getDim())
					->where("p.guild", $this->db->getMyguild());
			})->where("mode", "!=", "del")
			->select("o.name")
			->orderBy("o.name");
		$query->selectRaw(
			"COALESCE(" . $query->grammar->wrap("p.guild_rank_id") . ", 6)".
			$query->as("guild_rank_id")
		);
		$query->asObj()->each(function(DBRow $row) {
			$this->chatBot->guildmembers[$row->name] = $row->guild_rank_id;
		});
	}

	/** Remove someone from the online list that we added for "guild" */
	protected function delMemberFromOnline(string $member): int {
		return $this->db->table("online")
			->where("name", $member)
			->where("channel_type", "guild")
			->where("added_by", $this->db->getBotname())
			->delete();
	}

	/**
	 * @HandlesCommand("logon")
	 */
	public function logonMessageShowCommand(CmdContext $context): void {
		$logonMessage = $this->preferences->get($context->char->name, 'logon_msg');

		if ($logonMessage === null || $logonMessage === '') {
			$msg = "Your logon message has not been set.";
		} else {
			$msg = "{$context->char->name} logon: {$logonMessage}";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("logon")
	 */
	public function logonMessageSetCommand(CmdContext $context, string $logonMessage): void {
		if ($logonMessage === 'clear') {
			$this->preferences->save($context->char->name, 'logon_msg', '');
			$msg = "Your logon message has been cleared.";
		} elseif (strlen($logonMessage) <= $this->settingManager->getInt('max_logon_msg_size')??200) {
			$this->preferences->save($context->char->name, 'logon_msg', $logonMessage);
			$msg = "Your logon message has been set.";
		} else {
			$msg = "Your logon message is too large. Your logon message may contain a maximum of " . ($this->settingManager->getInt('max_logon_msg_size')??200) . " characters.";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("logoff")
	 */
	public function logoffMessageShowCommand(CmdContext $context): void {
		$logoffMessage = $this->preferences->get($context->char->name, 'logoff_msg');

		if ($logoffMessage === null || $logoffMessage === '') {
			$msg = "Your logoff message has not been set.";
		} else {
			$msg = "{$context->char->name} logoff: {$logoffMessage}";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("logoff")
	 */
	public function logoffMessageSetCommand(CmdContext $context, string $logoffMessage): void {
		if ($logoffMessage == 'clear') {
			$this->preferences->save($context->char->name, 'logoff_msg', '');
			$msg = "Your logoff message has been cleared.";
		} elseif (strlen($logoffMessage) <= $this->settingManager->getInt('max_logoff_msg_size')) {
			$this->preferences->save($context->char->name, 'logoff_msg', $logoffMessage);
			$msg = "Your logoff message has been set.";
		} else {
			$msg = "Your logoff message is too large. Your logoff message may contain a maximum of " . ($this->settingManager->getInt('max_logoff_msg_size')??200) . " characters.";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("lastseen")
	 */
	public function lastSeenCommand(CmdContext $context, PCharacter $name): void {
		$uid = $this->chatBot->get_uid($name());
		if (!$uid) {
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
	 * @HandlesCommand("recentseen")
	 */
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

		$query = $this->db->table(self::DB_TABLE . " AS o")
			->leftJoin("alts AS a", "o.name", "a.alt")
			->where("mode", "!=", "del")
			->where("logged_off", ">", $time)
			->orderByDesc("o.logged_off")
			->orderBy("o.name");
		$query->selectRaw($query->colFunc("COALESCE", ["a.main", "o.name"], "main")->getValue());
		$query->addSelect("o.logged_off", "o.name");
		$data = $query->asObj();

		if ($data->count() === 0) {
			$context->reply("No members recorded.");
			return;
		}

		$numRecentCount = 0;
		$highlight = false;

		$blob = "Org members who have logged off within the last <highlight>{$timeString}<end>.\n\n";

		$prevToon = '';
		foreach ($data as $row) {
			if ($row->main === $prevToon) {
				continue;
			}
			$prevToon = $row->main;
			$numRecentCount++;
			$alts = $this->text->makeChatcmd("alts", "/tell <myname> alts {$row->main}");
			$logged = $row->logged_off;
			$lastToon = $row->name;

			$character = "<pagebreak><highlight>{$row->main}<end> [{$alts}]\n".
				"<tab>Last seen as $lastToon on ".
				$this->util->date($logged) . "\n\n";
			if ($highlight === true) {
				$blob .= "<highlight>$character<end>";
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
	 * @HandlesCommand("notify")
	 * @Mask $action (on|add)
	 */
	public function notifyAddCommand(CmdContext $context, string $action, PCharacter $who): void {
		$name = $who();
		$uid = $this->chatBot->get_uid($name);

		if (!$uid) {
			$msg = "<highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$row = $this->db->table(self::DB_TABLE)
			->where("name", $name)
			->select("mode")
			->asObj()->first();

		if ($row !== null && $row->mode !== "del") {
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
		$this->buddylistManager->add($name, 'org');
		$this->chatBot->guildmembers[$name] = 6;
		$msg = "<highlight>{$name}<end> has been added to the Notify list.";

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("notify")
	 */
	public function notifyRemoveCommand(CmdContext $context, PRemove $action, PCharacter $who): void {
		$name = $who();
		$uid = $this->chatBot->get_uid($name);

		if (!$uid) {
			$msg = "<highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$row = $this->db->table(self::DB_TABLE)
			->where("name", $name)
			->select("mode")
			->asObj()->first();

		if ($row === null) {
			$msg = "<highlight>{$name}<end> is not on the guild roster.";
		} elseif ($row->mode == "del") {
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

	/**
	 * @HandlesCommand("updateorg")
	 */
	public function updateorgCommand(CmdContext $context): void {
		$context->reply("Starting Roster update");
		$this->updateOrgRoster([$context, "reply"], "Finished Roster update");
	}

	public function updateOrgRoster(?callable $callback=null, ...$args): void {
		if (!$this->isGuildBot()) {
			return;
		}
		$this->logger->notice("Starting Roster update");

		// Get the guild info
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			$this->chatBot->vars["dimension"],
			true,
			[$this, "updateRosterForGuild"],
			$callback,
			...$args
		);
	}

	public function updateRosterForGuild(?Guild $org, ?callable $callback, ...$args): void {
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

		$this->chatBot->ready = false;

		$this->db->beginTransaction();

		// Going through each member of the org and add or update his/her
		foreach ($org->members as $member) {
			// don't do anything if $member is the bot itself
			if (strtolower($member->name) === strtolower($this->chatBot->vars["name"])) {
				continue;
			}

			//If there exists already data about the character just update him/her
			if (isset($dbEntries[$member->name])) {
				if ($dbEntries[$member->name]["mode"] === "del") {
					// members who are not on notify should not be on the buddy list but should remain in the database
					$this->buddylistManager->remove($member->name, 'org');
					unset($this->chatBot->guildmembers[$member->name]);
				} else {
					// add org members who are on notify to buddy list
					$this->buddylistManager->add($member->name, 'org');
					$this->chatBot->guildmembers[$member->name] = $member->guild_rank_id ?? 0;

					// if member was added to notify list manually, switch mode to org and let guild roster update from now on
					if ($dbEntries[$member->name]["mode"] == "add") {
						$this->db->table(self::DB_TABLE)
							->where("name", $member->name)
							->update(["mode" => "org"]);
					}
				}
			//Else insert his/her data
			} else {
				// add new org members to buddy list
				$this->buddylistManager->add($member->name, 'org');
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

		if ($restart === true) {
			$this->loadGuildMembers();
		}
		if (isset($callback)) {
			$callback(...$args);
		}
	}

	public function dispatchRoutableEvent(Base $event): void {
		$re = new RoutableEvent();
		$re->type = RoutableEvent::TYPE_EVENT;
		$abbr = $this->settingManager->getString('relay_guild_abbreviation');
		$re->prependPath(new Source(
			Source::ORG,
			$this->chatBot->vars["my_guild"],
			($abbr === "none") ? null : $abbr
		));
		$re->setData($event);
		$this->messageHub->handle($re);
	}

	/**
	 * @Event("timer(24hrs)")
	 * @Description("Download guild roster xml and update guild members")
	 */
	public function downloadOrgRosterEvent(Event $eventObj): void {
		$this->updateOrgRoster();
	}

	/**
	 * @Event("orgmsg")
	 * @Description("Automatically update guild roster as characters join and leave the guild")
	 */
	public function autoNotifyOrgMembersEvent(AOChatEvent $eventObj): void {
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
						"dt" => time()
					]);
			}
			$this->db->table(self::DB_TABLE)
				->upsert(["mode" => "add", "name" => $name], "name");
			$this->buddylistManager->add($name, 'org');
			$this->chatBot->guildmembers[$name] = 6;

			// update character info
			$this->playerManager->getByNameAsync(function() {
			}, $name);
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
	 * @psalm-param callable(string) $callback
	 */
	public function getLogonForPlayer(callable $callback, ?Player $whois, string $player, bool $suppressAltList): void {
		$msg = '';
		$logonMsg = $this->preferences->get($player, 'logon_msg') ?? "";
		if ($logonMsg !== '') {
			$logonMsg = " - {$logonMsg}";
		}
		if ($whois === null) {
			$msg = "$player logged on";
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
					$altInfo->getAltsBlobAsync(
						/** @param string|string[] $blob */
						function($blob) use ($msg, $callback, $logonMsg): void {
							$blob = ((array)$blob)[0];
							$callback("{$msg}. {$blob}{$logonMsg}");
						},
						true
					);
					return;
				}
			}
		}

		$callback($msg.$logonMsg);
	}

	public function getLogonMessageAsync(string $player, bool $suppressAltList, callable $callback): void {
		if ($this->settingManager->getBool('first_and_last_alt_only')) {
			// if at least one alt/main is already online, don't show logon message
			$altInfo = $this->altsController->getAltInfo($player);
			if (count($altInfo->getOnlineAlts()) > 1) {
				return;
			}
		}

		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($callback, $player, $suppressAltList): void {
				$this->getLogonForPlayer($callback, $whois, $player, $suppressAltList);
			},
			$player
		);
	}

	/**
	 * @Event("logOn")
	 * @Description("Shows an org member logon in chat")
	 */
	public function orgMemberLogonMessageEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| !is_string($sender)) {
			return;
		}
		$suppressAltList = $this->settingManager->getBool('org_suppress_alt_list') ?? false;
		$this->getLogonMessageAsync($sender, $suppressAltList, function(string $msg) use ($sender): void {
			$this->chatBot->getUid($sender, function(?int $uid, string $msg, string $sender): void {
				$e = new Online();
				$e->char = new Character($sender, $uid);
				$e->online = true;
				$e->message = $msg;
				$this->dispatchRoutableEvent($e);
				$this->chatBot->sendGuild($msg, true);
			}, $msg, $sender);
		});
	}

	public function getLogoffMessage(string $player): ?string {
		if ($this->settingManager->getBool('first_and_last_alt_only')) {
			// if at least one alt/main is still online, don't show logoff message
			$altInfo = $this->altsController->getAltInfo($player);
			if (count($altInfo->getOnlineAlts()) > 0) {
				return null;
			}
		}

		$msg = "$player logged off.";
		$logoffMessage = $this->preferences->get($player, 'logoff_msg');
		if ($logoffMessage !== null && $logoffMessage !== '') {
			$msg .= " " . $logoffMessage;
		}
		return $msg;
	}

	/**
	 * @Event("logOff")
	 * @Description("Shows an org member logoff in chat")
	 */
	public function orgMemberLogoffMessageEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| !is_string($sender)) {
			return;
		}

		$this->chatBot->getUid($sender, function(?int $uid, string $sender): void {
			$msg = $this->getLogoffMessage($sender);
			$e = new Online();
			$e->char = new Character($sender, $uid);
			$e->online = false;
			$e->message = $msg;
			$this->dispatchRoutableEvent($e);
			if ($msg === null) {
				return;
			}

			$this->chatBot->sendGuild($msg, true);
		}, $sender);
	}

	/**
	 * @Event("logOff")
	 * @Description("Record org member logoff for lastseen command")
	 */
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
		return !empty($this->chatBot->vars["my_guild"])
			&& !empty($this->chatBot->vars["my_guild_id"]);
	}

	/**
	 * @Event("connect")
	 * @Description("Verifies that org name is correct")
	 */
	public function verifyOrgNameEvent(Event $eventObj): void {
		if (empty($this->chatBot->vars["my_guild"])) {
			return;
		}
		if (empty($this->chatBot->vars["my_guild_id"])) {
			$this->logger->warning("Org name '{$this->chatBot->vars["my_guild"]}' specified, but bot does not appear to belong to an org");
			return;
		}
		$gid = $this->getOrgChannelIdByOrgId($this->chatBot->vars["my_guild_id"]);
		$orgChannel = $this->chatBot->gid[$gid]??null;
		if (isset($orgChannel) && $orgChannel !== "Clan (name unknown)" && $orgChannel !== $this->chatBot->vars["my_guild"]) {
			$this->logger->warning("Org name '{$this->chatBot->vars["my_guild"]}' specified, but bot belongs to org '$orgChannel'");
		}
	}

	public function getOrgChannelIdByOrgId(int $orgId): ?string {
		foreach ($this->chatBot->grp as $gid => $status) {
			$string = unpack("N", substr((string)$gid, 1));
			if (ord(substr((string)$gid, 0, 1)) === 3 && $string[1] == $orgId) {
				return (string)$gid;
			}
		}
		return null;
	}
}
