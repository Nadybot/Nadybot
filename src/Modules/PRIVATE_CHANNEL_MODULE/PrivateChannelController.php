<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Exception;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	AOChatEvent,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	DB,
	Event,
	EventManager,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
	DBSchema\Audit,
	DBSchema\Member,
	DBSchema\Player,
	LoggerWrapper,
	MessageHub,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\ALTS\AltInfo,
	Modules\BAN\BanController,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	Routing\Character,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\Source,
	UserStateEvent,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OfflineEvent,
	ONLINE_MODULE\OnlineController,
	ONLINE_MODULE\OnlineEvent,
	ONLINE_MODULE\OnlinePlayer,
};

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'members',
 *		accessLevel = 'all',
 *		description = "Member list",
 *		help        = 'private_channel.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'member',
 *		accessLevel = 'guild',
 *		description = "Adds or removes a player to/from the members list",
 *		help        = 'private_channel.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'invite',
 *		accessLevel = 'guild',
 *		description = "Invite players to the private channel",
 *		help        = 'private_channel.txt',
 *		alias       = 'inviteuser'
 *	)
 *	@DefineCommand(
 *		command     = 'kick',
 *		accessLevel = 'guild',
 *		description = "Kick players from the private channel",
 *		help        = 'private_channel.txt',
 *		alias       = 'kickuser'
 *	)
 *	@DefineCommand(
 *		command     = 'autoinvite',
 *		accessLevel = 'member',
 *		description = "Enable or disable autoinvite",
 *		help        = 'autoinvite.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'count',
 *		accessLevel = 'all',
 *		description = "Shows how many characters are in the private channel",
 *		help        = 'count.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'kickall',
 *		accessLevel = 'guild',
 *		description = "Kicks all from the private channel",
 *		help        = 'kickall.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'join',
 *		accessLevel = 'member',
 *		description = "Join command for characters who want to join the private channel",
 *		help        = 'private_channel.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'leave',
 *		accessLevel = 'all',
 *		description = "Leave command for characters in private channel",
 *		help        = 'private_channel.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'lock',
 *		accessLevel = 'superadmin',
 *		description = "Kick everyone and lock the private channel",
 *		help        = 'lock.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'unlock',
 *		accessLevel = 'superadmin',
 *		description = "Allow people to join the private channel again",
 *		help        = 'lock.txt'
 *	)
 *	@ProvidesEvent("online(priv)")
 *	@ProvidesEvent("offline(priv)")
 *	@ProvidesEvent("member(add)")
 *	@ProvidesEvent("member(rem)")
 */
class PrivateChannelController {
	public const DB_TABLE = "members_<myname>";

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
	public EventManager $eventManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public BanController $banController;

	/** @Inject */
	public OnlineController $onlineController;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Logger */
	public LoggerWrapper $logger;

	/** If set, the private channel is currently locked for a reason */
	protected ?string $lockReason = null;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");

		$this->settingManager->add(
			$this->moduleName,
			"add_member_on_join",
			"Automatically add player as member when they join",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"autoinvite_default",
			"Enable autoinvite for new members by default",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"only_allow_faction",
			"Faction allowed on the bot - autoban everything else",
			"edit",
			"options",
			"all",
			"all;Omni;Neutral;Clan;not Omni;not Neutral;not Clan"
		);
		$this->settingManager->add(
			$this->moduleName,
			"priv_suppress_alt_list",
			"Do not show the altlist on join, just the name of the main",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"invite_banned_chars",
			"Should the bot allow inviting banned characters?",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"welcome_msg_string",
			"Message to send when welcoming new members",
			"edit",
			"text",
			"<link>Welcome to <myname></link>!",
			"<link>Welcome to <myname></link>!;Welcome to <myname>! Here is some <link>information to get you started</link>.",
			"",
			"mod",
			"welcome_msg.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			"lock_minrank",
			"Minimum rank allowed to join private channel during a lock",
			"edit",
			"rank",
			"superadmin",
			"",
			"",
			"superadmin"
		);
		$this->commandAlias->register(
			$this->moduleName,
			"member add",
			"adduser"
		);
		$this->commandAlias->register(
			$this->moduleName,
			"member del",
			"deluser"
		);
		$this->commandAlias->register(
			$this->moduleName,
			"member del",
			"remuser"
		);
		$this->settingManager->registerChangeListener(
			"welcome_msg_string",
			[$this, "validateWelcomeMsg"]
		);
	}

	public function validateWelcomeMsg(string $setting, string $old, string $new): void {
		if (preg_match("|&lt;link&gt;.+?&lt;/link&gt;|", $new)) {
			throw new Exception(
				"You have to use <highlight><symbol>htmldecode settings save ...<end> if your settings contain ".
				"tags like &lt;link&gt;, because the AO client escapes the tags. ".
				"This command is part of the DEV_MODULE."
			);
		}
		if (!preg_match("|<link>.+?</link>|", $new)) {
			throw new Exception(
				"Your message must contain a block of <highlight>&lt;link&gt;&lt;/link&gt;<end> which will ".
				"then be a popup with the actual welcome message. The link text sits between ".
				"the tags, e.g. <highlight>&lt;link&gt;click me&lt;/link&gt;<end>."
			);
		}
		if (substr_count($new, "<link>") > 1 || substr_count($new, "</link>") > 1) {
			throw new Exception(
				"Your message can only contain a single block of <highlight>&lt;link&gt;&lt;/link&gt;<end>."
			);
		}
		if (substr_count($new, "<") !== substr_count($new, ">")) {
			throw new Exception("Your text seems to be invalid HTML.");
		}
		$stripped = strip_tags($new);
		if (preg_match("/[<>]/", $stripped)) {
			throw new Exception("Your text seems to generate invalid HTML.");
		}
	}

	/**
	 * @HandlesCommand("members")
	 */
	public function membersCommand(CmdContext $context): void {
		/** @var Collection<Member> */
		$members = $this->db->table(self::DB_TABLE)
			->orderBy("name")
			->asObj(Member::class);
		$count = $members->count();
		if ($count === 0) {
			$context->reply("This bot has no members.");
			return;
		}
		$list = "<header2>Members of <myname><end>\n";
		foreach ($members as $member) {
			$online = $this->buddylistManager->isOnline($member->name);
			if (isset($this->chatBot->chatlist[$member->name])) {
				$status = "(<green>Online and in channel<end>)";
			} elseif ($online === true) {
				$status = "(<green>Online<end>)";
			} elseif ($online === false) {
				$status = "(<red>Offline<end>)";
			} else {
				$status = "(<orange>Unknown<end>)";
			}

			$list .= "<tab>{$member->name} {$status}\n";
		}

		$msg = $this->text->makeBlob("Members ($count)", $list);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("member")
	 * @Mask $action add
	 */
	public function addUserCommand(CmdContext $context, string $action, PCharacter $member): void {
		$msg = $this->addUser($member(), $context->char->name);

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("member")
	 */
	public function remUserCommand(CmdContext $context, PRemove $action, PCharacter $member): void {
		$msg = $this->removeUser($member(), $context->char->name);

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("invite")
	 */
	public function inviteCommand(CmdContext $context, PCharacter $char): void {
		$name = $char();
		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		if ($this->chatBot->vars["name"] == $name) {
			$msg = "You cannot invite the bot to its own private channel.";
			$context->reply($msg);
			return;
		}
		if (isset($this->chatBot->chatlist[$name])) {
			$msg = "<highlight>$name<end> is already in the private channel.";
			$context->reply($msg);
			return;
		}
		if ($this->isLockedFor($context->char->name)) {
			$context->reply("The private channel is currently <red>locked<end>: {$this->lockReason}");
			return;
		}
		$invitation = function() use ($name, $context): void {
			$msg = "Invited <highlight>{$name}<end> to this channel.";
			$this->chatBot->privategroup_invite($name);
			$audit = new Audit();
			$audit->actor = $context->char->name;
			$audit->actee = $name;
			$audit->action = AccessManager::INVITE;
			$this->accessManager->addAudit($audit);
			$msg2 = "You have been invited to the <highlight><myname><end> channel by <highlight>{$context->char->name}<end>.";
			$this->chatBot->sendMassTell($msg2, $name);

			$context->reply($msg);
		};
		if ($this->settingManager->getBool('invite_banned_chars')) {
			$invitation();
			return;
		}
		$this->banController->handleBan(
			$uid,
			$invitation,
			function() use ($name, $context): void {
				$msg = "<highlight>{$name}<end> is banned from <highlight><myname><end>.";
				$context->reply($msg);
			}
		);
	}

	/**
	 * @HandlesCommand("kick")
	 */
	public function kickCommand(CmdContext $context, PCharacter $char, ?string $reason): void {
		$name = $char();
		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
		} elseif (!isset($this->chatBot->chatlist[$name])) {
			$msg = "Character <highlight>{$name}<end> is not in the private channel.";
		} else {
			if ($this->accessManager->compareCharacterAccessLevels($context->char->name, $name) > 0) {
				$msg = "<highlight>{$name}<end> has been kicked from the private channel";
				if (isset($reason)) {
					$msg .= ": <highlight>{$reason}<end>";
				} else {
					$msg .= ".";
				}
				$this->chatBot->sendPrivate($msg);
				$this->chatBot->privategroup_kick($name);
				$audit = new Audit();
				$audit->actor = $context->char->name;
				$audit->actor = $name;
				$audit->action = AccessManager::KICK;
				$audit->value = $reason;
				$this->accessManager->addAudit($audit);
			} else {
				$msg = "You do not have the required access level to kick <highlight>$name<end>.";
			}
		}
		if ($context->isDM()) {
			$context->reply($msg);
		}
	}

	/**
	 * @HandlesCommand("autoinvite")
	 */
	public function autoInviteCommand(CmdContext $context, bool $status): void {
		if ($status) {
			$onOrOff = 1;
			$this->buddylistManager->add($context->char->name, 'member');
		} else {
			$onOrOff = 0;
		}

		if (!$this->db->table(self::DB_TABLE)->where("name", $context->char->name)->exists()) {
			$this->db->table(self::DB_TABLE)
				->insert([
					"name" => $context->char->name,
					"autoinv" => $onOrOff,
				]);
			$msg = "You have been added as a member of this bot. ".
				"Use <highlight><symbol>autoinvite<end> to control ".
				"your auto invite preference.";
			$event = new MemberEvent();
			$event->type = "member(add)";
			$event->sender = $context->char->name;
			$this->eventManager->fireEvent($event);
		} else {
			$this->db->table(self::DB_TABLE)
				->where("name", $context->char->name)
				->update(["autoinv" => $onOrOff]);
			$msg = "Your auto invite preference has been updated.";
		}

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 * @Mask $action (levels?|lvls?)
	 */
	public function countLevelCommand(CmdContext $context, string $action): void {
		$tl1 = 0;
		$tl2 = 0;
		$tl3 = 0;
		$tl4 = 0;
		$tl5 = 0;
		$tl6 = 0;
		$tl7 = 0;

		$data = $this->db->table("online AS o")
			->leftJoin("players AS p", function (JoinClause $join) {
				$join->on("o.name", "p.name")
					->where("p.dimension", $this->db->getDim());
			})->where("added_by", $this->db->getBotname())
			->where("channel_type", "priv")
			->asObj()->toArray();
		$numonline = count($data);
		foreach ($data as $row) {
			if ($row->level > 1 && $row->level <= 14) {
				$tl1++;
			} elseif ($row->level <= 49) {
				$tl2++;
			} elseif ($row->level <= 99) {
				$tl3++;
			} elseif ($row->level <= 149) {
				$tl4++;
			} elseif ($row->level <= 189) {
				$tl5++;
			} elseif ($row->level <= 204) {
				$tl6++;
			} else {
				$tl7++;
			}
		}
		$msg = "<highlight>$numonline<end> in total: ".
			"TL1: <highlight>$tl1<end>, ".
			"TL2: <highlight>$tl2<end>, ".
			"TL3: <highlight>$tl3<end>, ".
			"TL4: <highlight>$tl4<end>, ".
			"TL5: <highlight>$tl5<end>, ".
			"TL6: <highlight>$tl6<end>, ".
			"TL7: <highlight>$tl7<end>";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 * @Mask $action (all|profs?)
	 */
	public function countProfessionCommand(CmdContext $context, string $action): void {
		$online = [
			"Adventurer" => 0,
			"Agent" => 0,
			"Bureaucrat" => 0,
			"Doctor" => 0,
			"Enforcer" => 0,
			"Engineer" => 0,
			"Fixer" => 0,
			"Keeper" => 0,
			"Martial Artist" => 0,
			"Meta-Physicist" => 0,
			"Nano-Technician" => 0,
			"Soldier" => 0,
			"Trader" => 0,
			"Shade" => 0,
		];

		$query = $this->db->table("online AS o")
			->leftJoin("players AS p", function (JoinClause $join) {
				$join->on("o.name", "p.name")
					->where("p.dimension", $this->db->getDim());
			})->where("added_by", $this->db->getBotname())
			->where("channel_type", "priv")
			->groupBy("profession");
		$query->select($query->rawFunc("COUNT", "*", "count"), "profession");
		$data = $query->asObj()->toArray();
		$numonline = count($data);
		$msg = "<highlight>$numonline<end> in total: ";

		foreach ($data as $row) {
			$online[$row->profession] = $row->count;
		}

		$msg .= "<highlight>".$online['Adventurer']."<end> Adv, "
			. "<highlight>".$online['Agent']."<end> Agent, "
			. "<highlight>".$online['Bureaucrat']."<end> Crat, "
			. "<highlight>".$online['Doctor']."<end> Doc, "
			. "<highlight>".$online['Enforcer']."<end> Enf, "
			. "<highlight>".$online['Engineer']."<end> Eng, "
			. "<highlight>".$online['Fixer']."<end> Fix, "
			. "<highlight>".$online['Keeper']."<end> Keeper, "
			. "<highlight>".$online['Martial Artist']."<end> MA, "
			. "<highlight>".$online['Meta-Physicist']."<end> MP, "
			. "<highlight>".$online['Nano-Technician']."<end> NT, "
			. "<highlight>".$online['Soldier']."<end> Sol, "
			. "<highlight>".$online['Shade']."<end> Shade, "
			. "<highlight>".$online['Trader']."<end> Trader";

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 * @Mask $action (orgs?)
	 */
	public function countOrganizationCommand(CmdContext $context, string $action): void {
		$numOnline = $this->db->table("online")
			->where("added_by", $this->db->getBotname())
			->where("channel_type", "priv")->count();

		if ($numOnline === 0) {
			$msg = "No characters in channel.";
			$context->reply($msg);
			return;
		}
		$query = $this->db->table("online AS o")
			->leftJoin("players AS p", function (JoinClause $join) {
				$join->on("o.name", 'p.name')
					->where("p.dimension", $this->db->getDim());
			})->where("added_by", $this->db->getBotname())
			->where("channel_type", "priv")
			->groupBy("guild");
		$query->orderByRaw($query->rawFunc('COUNT', '*') . ' desc')
			->orderByRaw($query->colFunc('AVG', 'level') . ' desc')
			->select("guild", $query->rawFunc("COUNT", "*", "cnt"))
			->addSelect($query->colFunc("AVG", "level", "avg_level"));
		$data = $query->asObj();
		$numOrgs = $data->count();

		$blob = '';
		foreach ($data as $row) {
			$guild = '(none)';
			if ($row->guild != '') {
				$guild = $row->guild;
			}
			$percent = $this->text->alignNumber(
				(int)round($row->cnt * 100 / $numOnline, 0),
				3
			);
			$avg_level = round((float)$row->avg_level, 1);
			$blob .= "{$percent}% <highlight>{$guild}<end> - {$row->cnt} member(s), average level {$avg_level}\n";
		}

		$msg = $this->text->makeBlob("Organizations ($numOrgs)", $blob);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 */
	public function countCommand(CmdContext $context, string $profession): void {
		$prof = $this->util->getProfessionName($profession);
		if ($prof === '') {
			$msg = "Please choose one of these professions: adv, agent, crat, doc, enf, eng, fix, keep, ma, mp, nt, sol, shade, trader or all";
			$context->reply($msg);
			return;
		}
		$data = $this->db->table("online AS o")
			->leftJoin("players AS p", function (JoinClause $join) {
				$join->on("o.name", 'p.name')
					->where("p.dimension", $this->db->getDim());
			})->where("added_by", $this->db->getBotname())
			->where("channel_type", "priv")
			->where("profession", $prof)
			->asObj();
		$numonline = $data->count();
		if ($numonline === 0) {
			$msg = "<highlight>$numonline<end> {$prof}s.";
			$context->reply($msg);
			return;
		}
		$msg = "<highlight>$numonline<end> $prof:";

		foreach ($data as $row) {
			if ($row->afk !== "") {
				$afk = " <red>*AFK*<end>";
			} else {
				$afk = "";
			}
			$msg .= " [<highlight>{$row->name}<end> - {$row->level}{$afk}]";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("kickall")
	 * @Mask $action now
	 */
	public function kickallNowCommand(CmdContext $context, string $action): void {
		$this->chatBot->privategroup_kick_all();
	}

	/**
	 * @HandlesCommand("kickall")
	 */
	public function kickallCommand(CmdContext $context): void {
		$msg = "Everyone will be kicked from this channel in 10 seconds. [by <highlight>{$context->char->name}<end>]";
		$this->chatBot->sendPrivate($msg);
		$this->timer->callLater(10, [$this->chatBot, 'privategroup_kick_all']);
	}

	/**
	 * @HandlesCommand("join")
	 */
	public function joinCommand(CmdContext $context): void {
		if ($this->isLockedFor($context->char->name)) {
			$context->reply("The private channel is currently <red>locked<end>: {$this->lockReason}");
			return;
		}
		if (isset($this->chatBot->chatlist[$context->char->name])) {
			$msg = "You are already in the private channel.";
			$context->reply($msg);
			return;
		}
		$this->chatBot->privategroup_invite($context->char->name);
		if (!$this->settingManager->getBool('add_member_on_join')) {
			return;
		}
		if ($this->db->table(self::DB_TABLE)->where("name", $context->char->name)->exists()) {
			return;
		}
		$autoInvite = $this->settingManager->getBool('autoinvite_default');
		$this->db->table(self::DB_TABLE)
			->insert([
				"name" => $context->char->name,
				"autoinv" => $autoInvite,
			]);
		$msg = "You have been added as a member of this bot. ".
			"Use <highlight><symbol>autoinvite<end> to control your ".
			"auto invite preference.";
		$event = new MemberEvent();
		$event->type = "member(add)";
		$event->sender = $context->char->name;
		$this->eventManager->fireEvent($event);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("leave")
	 */
	public function leaveCommand(CmdContext $context): void {
		$this->chatBot->privategroup_kick($context->char->name);
	}

	/**
	 * @HandlesCommand("lock")
	 */
	public function lockCommand(CmdContext $context, string $reason): void {
		if (isset($this->lockReason)) {
			$this->lockReason = trim($reason);
			$context->reply("Lock reason changed.");
			return;
		}
		$this->lockReason = trim($reason);
		$this->chatBot->sendPrivate("The private chat has been <red>locked<end> by {$context->char->name}: <highlight>{$this->lockReason}<end>");
		$alRequired = $this->settingManager->getString('lock_minrank')??"superadmin";
		foreach ($this->chatBot->chatlist as $char => $online) {
			$alChar = $this->accessManager->getAccessLevelForCharacter($char);
			if ($this->accessManager->compareAccessLevels($alChar, $alRequired) < 0) {
				$this->chatBot->privategroup_kick($char);
			}
		}
		$context->reply("You <red>locked<end> the private channel: {$this->lockReason}");
		$audit = new Audit();
		$audit->actor = $context->char->name;
		$audit->action = AccessManager::LOCK;
		$audit->value = $this->lockReason;
		$this->accessManager->addAudit($audit);
	}

	/**
	 * @HandlesCommand("unlock")
	 */
	public function unlockCommand(CmdContext $context): void {
		if (!isset($this->lockReason)) {
			$context->reply("The private channel is currently not locked.");
			return;
		}
		unset($this->lockReason);
		$this->chatBot->sendPrivate("The private chat is now <green>open<end> again.");
		$context->reply("You <green>unlocked<end> the private channel.");
		$audit = new Audit();
		$audit->actor = $context->char->name;
		$audit->action = AccessManager::UNLOCK;
		$this->accessManager->addAudit($audit);
	}

	/**
	 * @Event(name="timer(5m)",
	 * 	description="Send reminder if the private channel is locked")
	 */
	public function remindOfLock(): void {
		if (!isset($this->lockReason)) {
			return;
		}
		$msg = "Reminder: the private channel is currently <red>locked<end>!";
		$this->chatBot->sendGuild($msg, true);
		$this->chatBot->sendPrivate($msg, true);
	}

	/**
	 * @Event(name="connect",
	 * 	description="Adds all members as buddies")
	 */
	public function connectEvent(Event $eventObj): void {
		$this->db->table(self::DB_TABLE)
			->asObj(Member::class)
			->each(function (Member $member) {
				$this->buddylistManager->add($member->name, 'member');
			});
	}

	/**
	 * @Event(name="logOn",
	 * 	description="Auto-invite members on logon")
	 */
	public function logonAutoinviteEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		/** @var Member[] */
		$data = $this->db->table(self::DB_TABLE)
			->where("name", $sender)
			->where("autoinv", 1)
			->asObj(Member::class)
			->toArray();
		if (!count($data)) {
			return;
		}
		$uid = $this->chatBot->get_uid($eventObj->sender);
		if ($uid === false) {
			return;
		}
		if ($this->isLockedFor($sender)) {
			return;
		}
		$this->banController->handleBan(
			$uid,
			function (int $uid, string $sender): void {
				$channelName = "the <highlight><myname><end> channel";
				if ($this->settingManager->getBool('guild_channel_status') === false) {
					$channelName = "<highlight><myname><end>";
				}
				$msg = "You have been auto invited to {$channelName}. ".
					"Use <highlight><symbol>autoinvite<end> to control ".
					"your auto invite preference.";
				$this->chatBot->privategroup_invite($sender);
				$this->chatBot->sendMassTell($msg, $sender);
			},
			null,
			$sender
		);
	}

	protected function getLogonMessageForPlayer(callable $callback, ?Player $whois, string $player, bool $suppressAltList, AltInfo $altInfo): void {
		$privChannelName = "the private channel";
		if ($this->settingManager->getBool('guild_channel_status') === false) {
			$privChannelName = "<myname>";
		}
		if ($whois !== null) {
			$msg = $this->playerManager->getInfo($whois) . " has joined {$privChannelName}.";
		} else {
			$msg = "{$player} has joined {$privChannelName}.";
		}
		if ($suppressAltList) {
			if ($altInfo->main !== $player) {
				$msg .= " Alt of <highlight>{$altInfo->main}<end>";
			}
		} else {
			if (count($altInfo->getAllValidatedAlts()) > 0) {
				$altInfo->getAltsBlobAsync(
					/** @param string|string[] $blob */
					function($blob) use ($msg, $callback): void {
						$callback("{$msg} " . ((array)$blob)[0]);
					},
					true
				);
				return;
			}
		}
		$callback($msg);
	}

	public function getLogonMessageAsync(string $player, bool $suppressAltList, callable $callback): void {
		$altInfo = $this->altsController->getAltInfo($player);
		if ($this->settingManager->getBool('first_and_last_alt_only')) {
			// if at least one alt/main is already online, don't show logon message
			if (count($altInfo->getOnlineAlts()) > 1) {
				return;
			}
		}

		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($player, $suppressAltList, $callback, $altInfo): void {
				$this->getLogonMessageForPlayer($callback, $whois, $player, $suppressAltList, $altInfo);
			},
			$player
		);
	}

	public function dispatchRoutableEvent(object $event): void {
		$re = new RoutableEvent();
		$label = null;
		if (isset($this->chatBot->vars["my_guild"]) && strlen($this->chatBot->vars["my_guild"])) {
			$label = "Guest";
		}
		$re->type = RoutableEvent::TYPE_EVENT;
		$re->prependPath(new Source(Source::PRIV, $this->chatBot->char->name, $label));
		$re->setData($event);
		$this->messageHub->handle($re);
	}

	/**
	 * @Event(name="joinPriv",
	 * 	description="Displays a message when a character joins the private channel")
	 */
	public function joinPrivateChannelMessageEvent(AOChatEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$sender = $eventObj->sender;
		$suppressAltList = $this->settingManager->getBool('priv_suppress_alt_list')??false;

		$this->getLogonMessageAsync($sender, $suppressAltList, function(string $msg) use ($sender): void {
			$this->chatBot->getUid(
				$sender,
				function(?int $uid, string $name, string $msg): void {
					$e = new Online();
					$e->char = new Character($name, $uid);
					$e->online = true;
					$e->message = $msg;
					$this->dispatchRoutableEvent($e);
					$this->chatBot->sendPrivate($msg, true);
				},
				$sender,
				$msg
			);
		});
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($sender): void {
				if (!isset($whois)) {
					return;
				}
				$event = new OnlineEvent();
				$event->type = "online(priv)";
				$event->player = new OnlinePlayer();
				$event->channel = "priv";
				foreach ($whois as $key => $value) {
					$event->player->$key = $value;
				}
				$event->player->online = true;
				$altInfo = $this->altsController->getAltInfo($sender);
				$event->player->pmain = $altInfo->main;
				$this->eventManager->fireEvent($event);
			},
			$sender
		);
	}

	/**
	 * @Event(name="joinPriv",
	 * 	description="Autoban players of unwanted factions when they join the bot")
	 */
	public function autobanOnJoin(AOChatEvent $eventObj): void {
		$reqFaction = $this->settingManager->getString('only_allow_faction') ?? "all";
		if ($reqFaction === 'all' || !is_String($eventObj->sender)) {
			return;
		}
		$this->playerManager->getByNameAsync(
			[$this,"autobanUnwantedFactions"],
			$eventObj->sender
		);
	}

	/**
	 * Automatically ban players if they are not of the wanted faction
	 */
	public function autobanUnwantedFactions(?Player $whois): void {
		if (!isset($whois)) {
			return;
		}
		$reqFaction = $this->settingManager->getString('only_allow_faction');
		if ($reqFaction === 'all') {
			return;
		}
		// check faction limit
		if (
			in_array($reqFaction, ["Omni", "Clan", "Neutral"])
			&& $reqFaction === $whois->faction
		) {
			return;
		}
		if (in_array($reqFaction, ["not Omni", "not Clan", "not Neutral"], true)) {
			$tmp = explode(" ", $reqFaction);
			if ($tmp[1] !== $whois->faction) {
				return;
			}
		}
		// Ban
		$faction = strtolower($whois->faction);
		$this->banController->add(
			$whois->charid,
			$this->chatBot->vars['name'],
			null,
			sprintf(
				"Autoban, because %s %s %s",
				$whois->getPronoun(),
				$whois->getIsAre(),
				$faction
			)
		);
		$this->chatBot->sendPrivate(
			"<highlight>{$whois->name}<end> has been auto-banned. ".
			"Reason: <{$faction}>{$faction}<end>."
		);
		$this->chatBot->privategroup_kick($whois->name);
		$audit = new Audit();
		$audit->actor = $this->chatBot->char->name;
		$audit->actor = $whois->name;
		$audit->action = AccessManager::KICK;
		$audit->value = "auto-ban";
		$this->accessManager->addAudit($audit);
	}

	public function getLogoffMessage(string $player): ?string {
		if ($this->settingManager->getBool('first_and_last_alt_only')) {
			// if at least one alt/main is still online, don't show logoff message
			$altInfo = $this->altsController->getAltInfo($player);
			if (count($altInfo->getOnlineAlts()) > 0) {
				return null;
			}
		}

		$msg = "<highlight>{$player}<end> has left the private channel.";
		return $msg;
	}

	/**
	 * @Event(name="leavePriv",
	 * 	description="Displays a message when a character leaves the private channel")
	 */
	public function leavePrivateChannelMessageEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		$msg = $this->getLogoffMessage($sender);

		$this->chatBot->getUid(
			$sender,
			function(?int $uid, string $sender, ?string $msg): void {
				$e = new Online();
				$e->char = new Character($sender, $uid);
				$e->online = false;
				if (isset($msg)) {
					$e->message = $msg;
				}
				$this->dispatchRoutableEvent($e);
			},
			$sender,
			$msg
		);

		$event = new OfflineEvent();
		$event->type = "offline(priv)";
		$event->player = $sender;
		$event->channel = "priv";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @Event(name="joinPriv",
	 * 	description="Updates the database when a character joins the private channel")
	 */
	public function joinPrivateChannelRecordEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		$this->onlineController->addPlayerToOnlineList(
			$sender,
			$this->chatBot->vars['my_guild'] . ' Guests',
			'priv'
		);
	}

	/**
	 * @Event(name="leavePriv",
	 * 	description="Updates the database when a character leaves the private channel")
	 */
	public function leavePrivateChannelRecordEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		$this->onlineController->removePlayerFromOnlineList($sender, 'priv');
	}

	/**
	 * @Event(name="joinPriv",
	 * 	description="Sends the online list to people as they join the private channel")
	 */
	public function joinPrivateChannelShowOnlineEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!is_string($sender)) {
			return;
		}
		$msg = $this->onlineController->getOnlineList();
		$this->chatBot->sendMassTell($msg, $sender);
	}

	public function addUser(string $name, string $sender): string {
		$autoInvite = $this->settingManager->getBool('autoinvite_default');
		$name = ucfirst(strtolower($name));
		$uid = $this->chatBot->get_uid($name);
		if ($this->chatBot->vars["name"] == $name) {
			return "You cannot add the bot as a member of itself.";
		} elseif (!$uid || $uid < 0) {
			return "Character <highlight>$name<end> does not exist.";
		}
		// always add in case they were removed from the buddy list for some reason
		$this->buddylistManager->add($name, 'member');
		if ($this->db->table(self::DB_TABLE)->where("name", $name)->exists()) {
			return "<highlight>$name<end> is already a member of this bot.";
		}
		$this->db->table(self::DB_TABLE)
			->insert([
				"name" => $name,
				"autoinv" => $autoInvite,
			]);
		$event = new MemberEvent();
		$event->type = "member(add)";
		$event->sender = $name;
		$this->eventManager->fireEvent($event);
		$audit = new Audit();
		$audit->actor = $sender;
		$audit->actee = $name;
		$audit->action = AccessManager::ADD_RANK;
		$audit->value = (string)$this->accessManager->getAccessLevels()["member"];
		$this->accessManager->addAudit($audit);
		return "<highlight>$name<end> has been added as a member of this bot.";
	}

	/**
	 * @Event(name="member(add)",
	 * 	description="Send welcome message data/welcome.txt to new members")
	 */
	public function sendWelcomeMessage(MemberEvent $event): void {
		$dataPath = $this->chatBot->vars["datafolder"] ?? "./data";
		if (!@file_exists("{$dataPath}/welcome.txt")) {
			return;
		}
		error_clear_last();
		$content = @file_get_contents("{$dataPath}/welcome.txt");
		if ($content === false) {
			$error = error_get_last();
			if (isset($error)) {
				$error = ": " . $error["message"];
			} else {
				$error = "";
			}
			$this->logger->error("Error reading {$dataPath}/welcome.txt{$error}");
			return;
		}
		$msg = $this->settingManager->getString("welcome_msg_string")??"<link>Welcome</link>!";
		if (preg_match("/^(.*)<link>(.*?)<\/link>(.*)$/", $msg, $matches)) {
			$msg = (array)$this->text->makeBlob($matches[2], $content);
			foreach ($msg as &$part) {
				$part = "{$matches[1]}{$part}{$matches[3]}";
			}
		} else {
			$msg = $this->text->makeBlob("Welcome to <myname>!", $content);
		}
		$this->chatBot->sendMassTell($msg, $event->sender);
	}

	public function removeUser(string $name, string $sender): string {
		$name = ucfirst(strtolower($name));

		if (!$this->db->table(self::DB_TABLE)->where("name", $name)->delete()) {
			return "<highlight>$name<end> is not a member of this bot.";
		}
		$this->buddylistManager->remove($name, 'member');
		$event = new MemberEvent();
		$event->type = "member(rem)";
		$event->sender = $name;
		$this->eventManager->fireEvent($event);
		$audit = new Audit();
		$audit->actor = $sender;
		$audit->actee = $name;
		$audit->action = AccessManager::DEL_RANK;
		$audit->value = (string)$this->accessManager->getAccessLevels()["member"];
		$this->accessManager->addAudit($audit);
		return "<highlight>$name<end> has been removed as a member of this bot.";
	}

	/**
	 * Check if the private channel is currently locked for a character
	 */
	public function isLockedFor(string $sender): bool {
		if (!isset($this->lockReason)) {
			return false;
		}
		$alSender = $this->accessManager->getAccessLevelForCharacter($sender);
		$alRequired = $this->settingManager->getString('lock_minrank')??"superadmin";
		return $this->accessManager->compareAccessLevels($alSender, $alRequired) < 0;
	}
}
