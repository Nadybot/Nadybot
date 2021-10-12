<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Exception;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	AOChatEvent,
	BuddylistManager,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	EventManager,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
	DBSchema\Member,
	LoggerWrapper,
	MessageHub,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	UserStateEvent,
};
use Nadybot\Core\DBSchema\Audit;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\ALTS\AltInfo;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\Events\Online;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\{
	ONLINE_MODULE\OfflineEvent,
	ONLINE_MODULE\OnlineController,
	ONLINE_MODULE\OnlineEvent,
	ONLINE_MODULE\OnlinePlayer,
	RELAY_MODULE\RelayController
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

	/**
	 * @var \Nadybot\Core\Nadybot $chatBot
	 * @Inject
	 */
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
	public RelayController $relayController;

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
	 * @Matches("/^members$/i")
	 */
	public function membersCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Collection<Member> */
		$members = $this->db->table(self::DB_TABLE)
			->orderBy("name")
			->asObj(Member::class);
		$count = $members->count();
		if ($count === 0) {
			$sendto->reply("This bot has no members.");
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("member")
	 * @Matches("/^member add ([a-z].+)$/i")
	 */
	public function addUserCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->addUser($args[1], $sender);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("member")
	 * @Matches("/^member (?:del|rem|rm|delete|remove) ([a-z].+)$/i")
	 */
	public function remUserCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->removeUser($args[1], $sender);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("invite")
	 * @Matches("/^invite ([a-z].+)$/i")
	 */
	public function inviteCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		if ($this->chatBot->vars["name"] == $name) {
			$msg = "You cannot invite the bot to its own private channel.";
			$sendto->reply($msg);
			return;
		}
		if (isset($this->chatBot->chatlist[$name])) {
			$msg = "<highlight>$name<end> is already in the private channel.";
			$sendto->reply($msg);
			return;
		}
		if ($this->isLockedFor($sender)) {
			$sendto->reply("The private channel is currently <red>locked<end>: {$this->lockReason}");
			return;
		}
		$invitation = function() use ($name, $sendto, $sender): void {
			$msg = "Invited <highlight>$name<end> to this channel.";
			$this->chatBot->privategroup_invite($name);
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->actee = $name;
			$audit->action = AccessManager::INVITE;
			$this->accessManager->addAudit($audit);
			$msg2 = "You have been invited to the <highlight><myname><end> channel by <highlight>$sender<end>.";
			$this->chatBot->sendMassTell($msg2, $name);

			$sendto->reply($msg);
		};
		if ($this->settingManager->getBool('invite_banned_chars')) {
			$invitation();
			return;
		}
		$this->banController->handleBan(
			$uid,
			$invitation,
			function() use ($name, $sendto): void {
				$msg = "<highlight>{$name}<end> is banned from <highlight><myname><end>.";
				$sendto->reply($msg);
			}
		);
	}

	/**
	 * @HandlesCommand("kick")
	 * @Matches("/^kick ([^ ]+)$/i")
	 * @Matches("/^kick ([^ ]+) (?<reason>.+)$/i")
	 */
	public function kickCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$msg = "Character <highlight>{$name}<end> does not exist.";
		} elseif (!isset($this->chatBot->chatlist[$name])) {
			$msg = "Character <highlight>{$name}<end> is not in the private channel.";
		} else {
			if ($this->accessManager->compareCharacterAccessLevels($sender, $name) > 0) {
				$msg = "<highlight>$name<end> has been kicked from the private channel";
				if (isset($args["reason"])) {
					$msg .= ": <highlight>{$args['reason']}<end>";
				} else {
					$msg .= ".";
				}
				$this->chatBot->sendPrivate($msg);
				$this->chatBot->privategroup_kick($name);
				$audit = new Audit();
				$audit->actor = $sender;
				$audit->actor = $name;
				$audit->action = AccessManager::KICK;
				$audit->value = $args['reason']??null;
				$this->accessManager->addAudit($audit);
			} else {
				$msg = "You do not have the required access level to kick <highlight>$name<end>.";
			}
		}
		if ($channel !== "priv") {
			$sendto->reply($msg);
		}
	}

	/**
	 * @HandlesCommand("autoinvite")
	 * @Matches("/^autoinvite (on|off)$/i")
	 */
	public function autoInviteCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($args[1] === 'on') {
			$onOrOff = 1;
			$this->buddylistManager->add($sender, 'member');
		} else {
			$onOrOff = 0;
		}

		if (!$this->db->table(self::DB_TABLE)->where("name", $sender)->exists()) {
			$this->db->table(self::DB_TABLE)
				->insert([
					"name" => $sender,
					"autoinv" => $onOrOff,
				]);
			$msg = "You have been added as a member of this bot. ".
				"Use <highlight><symbol>autoinvite<end> to control ".
				"your auto invite preference.";
			$event = new MemberEvent();
			$event->type = "member(add)";
			$event->sender = $sender;
			$this->eventManager->fireEvent($event);
		} else {
			$this->db->table(self::DB_TABLE)
				->where("name", $sender)
				->update(["autoinv" => $onOrOff]);
			$msg = "Your auto invite preference has been updated.";
		}

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 * @Matches("/^count (levels?|lvls?)$/i")
	 */
	public function countLevelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 * @Matches("/^count (all|profs?)$/i")
	 */
	public function countProfessionCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$online["Adventurer"] = 0;
		$online["Agent"] = 0;
		$online["Bureaucrat"] = 0;
		$online["Doctor"] = 0;
		$online["Enforcer"] = 0;
		$online["Engineer"] = 0;
		$online["Fixer"] = 0;
		$online["Keeper"] = 0;
		$online["Martial Artist"] = 0;
		$online["Meta-Physicist"] = 0;
		$online["Nano-Technician"] = 0;
		$online["Soldier"] = 0;
		$online["Trader"] = 0;
		$online["Shade"] = 0;

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

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 * @Matches("/^count orgs?$/i")
	 */
	public function countOrganizationCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$numOnline = $this->db->table("online")
			->where("added_by", $this->db->getBotname())
			->where("channel_type", "priv")->count();

		if ($numOnline === 0) {
			$msg = "No characters in channel.";
			$sendto->reply($msg);
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
			$avg_level = round($row->avg_level, 1);
			$blob .= "{$percent}% <highlight>{$guild}<end> - {$row->cnt} member(s), average level {$avg_level}\n";
		}

		$msg = $this->text->makeBlob("Organizations ($numOrgs)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("count")
	 * @Matches("/^count (.*)$/i")
	 */
	public function countCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$prof = $this->util->getProfessionName($args[1]);
		if ($prof === '') {
			$msg = "Please choose one of these professions: adv, agent, crat, doc, enf, eng, fix, keep, ma, mp, nt, sol, shade, trader or all";
			$sendto->reply($msg);
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
			$sendto->reply($msg);
			return;
		}
		$msg = "<highlight>$numonline<end> $prof:";

		foreach ($data as $row) {
			if ($row->afk !== "") {
				$afk = " <red>*AFK*<end>";
			} else {
				$afk = "";
			}
			$msg .= " [<highlight>$row->name<end> - ".$row->level.$afk."]";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("kickall")
	 * @Matches("/^kickall now$/i")
	 */
	public function kickallNowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->chatBot->privategroup_kick_all();
	}

	/**
	 * @HandlesCommand("kickall")
	 * @Matches("/^kickall( now)?$/i")
	 */
	public function kickallCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = "Everyone will be kicked from this channel in 10 seconds. [by <highlight>$sender<end>]";
		$this->chatBot->sendPrivate($msg);
		$this->timer->callLater(10, [$this->chatBot, 'privategroup_kick_all']);
	}

	/**
	 * @HandlesCommand("join")
	 * @Matches("/^join$/i")
	 */
	public function joinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($this->isLockedFor($sender)) {
			$sendto->reply("The private channel is currently <red>locked<end>: {$this->lockReason}");
			return;
		}
		if (isset($this->chatBot->chatlist[$sender])) {
			$msg = "You are already in the private channel.";
			$sendto->reply($msg);
			return;
		}
		$this->chatBot->privategroup_invite($sender);
		if (!$this->settingManager->getBool('add_member_on_join')) {
			return;
		}
		if ($this->db->table(self::DB_TABLE)->where("name", $sender)->exists()) {
			return;
		}
		$autoInvite = $this->settingManager->getBool('autoinvite_default');
		$this->db->table(self::DB_TABLE)
			->insert([
				"name" => $sender,
				"autoinv" => $autoInvite,
			]);
		$msg = "You have been added as a member of this bot. ".
			"Use <highlight><symbol>autoinvite<end> to control your ".
			"auto invite preference.";
		$event = new MemberEvent();
		$event->type = "member(add)";
		$event->sender = $sender;
		$this->eventManager->fireEvent($event);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("leave")
	 * @Matches("/^leave$/i")
	 */
	public function leaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->chatBot->privategroup_kick($sender);
	}

	/**
	 * @HandlesCommand("lock")
	 * @Matches("/^lock\s+(?<reason>.+)$/i")
	 */
	public function lockCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (isset($this->lockReason)) {
			$this->lockReason = trim($args['reason']);
			$sendto->reply("Lock reason changed.");
			return;
		}
		$this->lockReason = trim($args['reason']);
		$this->chatBot->sendPrivate("The private chat has been <red>locked<end> by {$sender}: <highlight>{$this->lockReason}<end>");
		$alRequired = $this->settingManager->getString('lock_minrank');
		foreach ($this->chatBot->chatlist as $char => $online) {
			$alChar = $this->accessManager->getAccessLevelForCharacter($char);
			if ($this->accessManager->compareAccessLevels($alChar, $alRequired) < 0) {
				$this->chatBot->privategroup_kick($char);
			}
		}
		$sendto->reply("You <red>locked<end> the private channel: {$this->lockReason}");
	}

	/**
	 * @HandlesCommand("unlock")
	 * @Matches("/^unlock$/i")
	 */
	public function unlockCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->lockReason)) {
			$sendto->reply("The private channel is currently not locked.");
			return;
		}
		unset($this->lockReason);
		$this->chatBot->sendPrivate("The private chat is now <green>open<end> again.");
		$sendto->reply("You <green>unlocked<end> the private channel.");
	}

	/**
	 * @Event("timer(5m)")
	 * @Description("Send reminder if the private channel is locked")
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
	 * @Event("connect")
	 * @Description("Adds all members as buddies")
	 */
	public function connectEvent(Event $eventObj): void {
		$this->db->table(self::DB_TABLE)
			->asObj(Member::class)
			->each(function (Member $member) {
				$this->buddylistManager->add($member->name, 'member');
			});
	}

	/**
	 * @Event("logOn")
	 * @Description("Auto-invite members on logon")
	 */
	public function logonAutoinviteEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
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
					function(string $blob) use ($msg, $callback): void {
						$callback("{$msg} {$blob}");
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
	 * @Event("joinPriv")
	 * @Description("Displays a message when a character joins the private channel")
	 */
	public function joinPrivateChannelMessageEvent(Event $eventObj) {
		$sender = $eventObj->sender;
		$suppressAltList = $this->settingManager->getBool('priv_suppress_alt_list');

		$this->getLogonMessageAsync($sender, $suppressAltList, function(string $msg) use ($sender): void {
			$e = new Online();
			$e->char = new Character($sender, $this->chatBot->get_uid($sender));
			$e->online = true;
			$e->message = $msg;
			$this->dispatchRoutableEvent($e);
			$this->chatBot->sendPrivate($msg, true);
		});
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($sender): void {
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
	 * @Event("joinPriv")
	 * @Description("Autoban players of unwanted factions when they join the bot")
	 */
	public function autobanOnJoin(AOChatEvent $eventObj): void {
		$reqFaction = $this->settingManager->getString('only_allow_faction');
		if ($reqFaction === 'all') {
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
		if (in_array($reqFaction, ["not Omni", "not Clan", "not Neutral"])) {
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
	 * @Event("leavePriv")
	 * @Description("Displays a message when a character leaves the private channel")
	 */
	public function leavePrivateChannelMessageEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$msg = $this->getLogoffMessage($sender);

		$e = new Online();
		$e->char = new Character($sender, $this->chatBot->get_uid($sender));
		$e->online = false;
		if (isset($msg)) {
			$e->message = $msg;
		}
		$this->dispatchRoutableEvent($e);

		if ($msg === null) {
			return;
		}

		$event = new OfflineEvent();
		$event->type = "offline(priv)";
		$event->player = $sender;
		$event->channel = "priv";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Updates the database when a character joins the private channel")
	 */
	public function joinPrivateChannelRecordEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$this->onlineController->addPlayerToOnlineList(
			$sender,
			$this->chatBot->vars['my_guild'] . ' Guests',
			'priv'
		);
	}

	/**
	 * @Event("leavePriv")
	 * @Description("Updates the database when a character leaves the private channel")
	 */
	public function leavePrivateChannelRecordEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$this->onlineController->removePlayerFromOnlineList($sender, 'priv');
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Sends the online list to people as they join the private channel")
	 */
	public function joinPrivateChannelShowOnlineEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
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
	 * @Event("member(add)")
	 * @Description("Send welcome message data/welcome.txt to new members")
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
			$this->logger->log('ERROR', "Error reading {$dataPath}/welcome.txt{$error}");
			return;
		}
		$msg = $this->settingManager->getString("welcome_msg_string");
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
		$alRequired = $this->settingManager->getString('lock_minrank');
		return $this->accessManager->compareAccessLevels($alSender, $alRequired) < 0;
	}
}
