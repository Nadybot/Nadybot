<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\{
	AccessManager,
	BuddylistManager,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
	DBSchema\Member,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	RELAY_MODULE\RelayController
};

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
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
 */
class PrivateChannelController {

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
	public SettingManager $settingManager;
	
	/** @Inject */
	public BuddylistManager $buddylistManager;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public AltsController $altsController;
	
	/** @Inject */
	public AccessManager $accessManager;
	
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
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "private_chat");
		
		$this->settingManager->add(
			$this->moduleName,
			"guest_color_channel",
			"Color for Private Channel relay(ChannelName)",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"guest_color_guild",
			"Private Channel relay color in guild channel",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"guest_color_guest",
			"Private Channel relay color in private channel",
			"edit",
			"color",
			"<font color=#C3C3C3>"
		);
		$this->settingManager->add(
			$this->moduleName,
			"guest_relay",
			"Relay the Private Channel with the Guild Channel",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"guest_relay_commands",
			"Relay commands and results from/to Private Channel",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
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
			"guest_relay_ignore",
			'Names of people not to relay into the private channel',
			'edit',
			'text',
			'',
			'none'
		);
		$this->settingManager->add(
			$this->moduleName,
			"guest_relay_filter",
			'RegEx filter for relaying into Private Channel',
			'edit',
			'text',
			'',
			'none'
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
	}

	/**
	 * @HandlesCommand("members")
	 * @Matches("/^members$/i")
	 */
	public function membersCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Member[] */
		$members = $this->db->fetchAll(
			Member::class,
			"SELECT * FROM members_<myname> ORDER BY `name`"
		);
		$count = count($members);
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
		$msg = $this->addUser($args[1]);

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("member")
	 * @Matches("/^member (?:del|rem|rm|delete|remove) ([a-z].+)$/i")
	 */
	public function remUserCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->removeUser($args[1]);

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
		$msg = "Invited <highlight>$name<end> to this channel.";
		$this->chatBot->privategroup_invite($name);
		$msg2 = "You have been invited to the <highlight><myname><end> channel by <highlight>$sender<end>.";
		$this->chatBot->sendTell($msg2, $name);

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("kick")
	 * @Matches("/^kick (.+)$/i")
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
				$msg = "<highlight>$name<end> has been kicked from the private channel.";
				$this->chatBot->privategroup_kick($name);
			} else {
				$msg = "You do not have the required access level to kick <highlight>$name<end>.";
			}
		}
		$sendto->reply($msg);
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
			$this->buddylistManager->remove($sender, 'member');
		}

		/** @var ?Member */
		$data = $this->db->fetch(
			Member::class,
			"SELECT * FROM members_<myname> WHERE `name` = ?",
			$sender
		);
		if ($data === null) {
			$this->db->exec("INSERT INTO members_<myname> (`name`, `autoinv`) VALUES (?, ?)", $sender, $onOrOff);
			$msg = "You have been added as a member of this bot. ".
				"Use <highlight><symbol>autoinvite<end> to control ".
				"your auto invite preference.";
		} else {
			$this->db->exec("UPDATE members_<myname> SET autoinv = ? WHERE name = ?", $onOrOff, $sender);
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

		$data = $this->db->query("SELECT * FROM online o LEFT JOIN players p ON (o.name = p.name AND p.dimension = '<dim>') WHERE added_by = '<myname>' AND channel_type = 'priv'");
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

		$data = $this->db->query(
			"SELECT count(*) AS count, profession ".
			"FROM online o ".
			"LEFT JOIN players p ON (o.name = p.name AND p.dimension = '<dim>') ".
			"WHERE added_by = '<myname>' AND channel_type = 'priv' ".
			"GROUP BY `profession`"
		);
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
		$sql = "SELECT COUNT(*) AS num_online FROM online WHERE added_by = '<myname>' AND channel_type = 'priv'";
		$data = $this->db->queryRow($sql);
		$numOnline = $data->num_online;

		if ($numOnline === 0) {
			$msg = "No characters in channel.";
			$sendto->reply($msg);
			return;
		}
		$sql = "SELECT `guild`, count(*) AS cnt, AVG(level) AS avg_level ".
			"FROM online o ".
			"LEFT JOIN players p ON (o.name = p.name AND p.dimension = '<dim>') ".
			"WHERE added_by = '<myname>' AND channel_type = 'priv' ".
			"GROUP BY `guild` ".
			"ORDER BY `cnt` DESC, `avg_level` DESC";
		$data = $this->db->query($sql);
		$numOrgs = count($data);

		$blob = '';
		foreach ($data as $row) {
			$guild = '(none)';
			if ($row->guild != '') {
				$guild = $row->guild;
			}
			$percent = $this->text->alignNumber(
				(int)round($row->cnt / $numOnline, 2) * 100,
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
		$data = $this->db->query(
			"SELECT * FROM online o ".
			"LEFT JOIN players p ON (o.name = p.name AND p.dimension = '<dim>') ".
			"WHERE added_by = '<myname>' AND channel_type = 'priv' AND `profession` = ? ".
			"ORDER BY `level`",
			$prof
		);
		$numonline = count($data);
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
		if (isset($this->chatBot->chatlist[$sender])) {
			$msg = "You are already in the private channel.";
			$sendto->reply($msg);
			return;
		}
		$this->chatBot->privategroup_invite($sender);
		if (!$this->settingManager->getBool('add_member_on_join')) {
			return;
		}
		/** @var ?Member */
		$row = $this->db->fetch(Member::class, "SELECT * FROM members_<myname> WHERE `name` = ?", $sender);
		if ($row !== null) {
			return;
		}
		$this->db->exec("INSERT INTO members_<myname> (`name`, `autoinv`) VALUES (?, ?)", $sender, '1');
		$msg = "You have been added as a member of this bot. ".
			"Use <highlight><symbol>autoinvite<end> to control your ".
			"auto invite preference.";
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
	 * @Event("connect")
	 * @Description("Adds all members as buddies who have auto-invite enabled")
	 */
	public function connectEvent(Event $eventObj): void {
		$sql = "SELECT * FROM members_<myname> WHERE autoinv = 1";
		/** @var Member[] */
		$members = $this->db->fetchAll(Member::class, $sql);
		foreach ($members as $member) {
			$this->buddylistManager->add($member->name, 'member');
		}
	}

	/**
	 * Check if a message by a sender should not be relayed due to filters
	 */
	public function isFilteredMessage(string $sender, string $message): bool {
		$toIgnore = array_diff(
			explode(";", strtolower($this->settingManager->getString('guest_relay_ignore'))),
			[""]
		);
		if (in_array(strtolower($sender), $toIgnore)) {
			return true;
		}
		if (strlen($regexpFilter = $this->settingManager->getString('guest_relay_filter'))) {
			$escapedFilter = str_replace("/", "\\/", $regexpFilter);
			if (@preg_match("/$escapedFilter/", $message)) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 * @Event("guild")
	 * @Description("Private channel relay from guild channel")
	 */
	public function relayPrivateChannelEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$message = $eventObj->message;
	
		// Check if the private channel relay is enabled
		if (!$this->settingManager->getBool("guest_relay")) {
			return;
		}

		// Check that it's not a command or if it is a command, check that guest_relay_commands is not disabled
		if ($message[0] === $this->settingManager->get("symbol")
			&& !$this->settingManager->getBool("guest_relay_commands")) {
			return;
		}

		if ($this->isFilteredMessage($sender, $message)) {
			return;
		}

		$guestColorChannel = $this->settingManager->get("guest_color_channel");
		$guestColorGuest = $this->settingManager->get("guest_color_guest");

		if (count($this->chatBot->chatlist) === 0) {
			return;
		}
		//Relay the message to the private channel if there is at least 1 char in private channel
		$guildNameForRelay = $this->relayController->getGuildAbbreviation();
		if (!$this->util->isValidSender($sender)) {
			// for relaying city alien raid messages where $sender == -1
			$msg = "<end>{$guestColorChannel}[$guildNameForRelay]<end> {$guestColorGuest}{$message}<end>";
		} else {
			$msg = "<end>{$guestColorChannel}[$guildNameForRelay]<end> ".$this->text->makeUserlink($sender).": {$guestColorGuest}{$message}<end>";
		}
		$this->chatBot->sendPrivate($msg, true);
	}
	
	/**
	 * @Event("priv")
	 * @Description("Guild channel relay from priv channel")
	 */
	public function relayGuildChannelEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$message = $eventObj->message;
		
		// Check if the private channel relay is enabled
		if (!$this->settingManager->getBool("guest_relay")) {
			return;
		}

		// Check that it's not a command or if it is a command, check that guest_relay_commands is not disabled
		if ($message[0] == $this->settingManager->get("symbol")
			&& !$this->settingManager->getBool("guest_relay_commands")) {
			return;
		}

		$guestColorChannel = $this->settingManager->get("guest_color_channel");
		$guestColorGuild = $this->settingManager->get("guest_color_guild");

		//Relay the message to the guild channel
		$msg = "<end>{$guestColorChannel}[Guest]<end> ".
			$this->text->makeUserlink($sender).
			": {$guestColorGuild}{$message}<end>";
		$this->chatBot->sendGuild($msg, true);
	}
	
	/**
	 * @Event("logOn")
	 * @Description("Auto-invite members on logon")
	 */
	public function logonAutoinviteEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		/** @var Member[] */
		$data = $this->db->fetchAll(
			Member::class,
			"SELECT * FROM members_<myname> WHERE name = ? AND autoinv = ?",
			$sender,
			1
		);
		if (!count($data)) {
			return;
		}
		$msg = "You have been auto invited to the <highlight><myname><end> channel. ".
			"Use <highlight><symbol>autoinvite<end> to control ".
			"your auto invite preference.";
		$this->chatBot->privategroup_invite($sender);
		$this->chatBot->sendTell($msg, $sender);
	}
	
	/**
	 * @Event("joinPriv")
	 * @Description("Displays a message when a character joins the private channel")
	 */
	public function joinPrivateChannelMessageEvent(Event $eventObj) {
		$sender = $eventObj->sender;
		$whois = $this->playerManager->getByName($sender);

		$altInfo = $this->altsController->getAltInfo($sender);

		if ($whois !== null) {
			if (count($altInfo->alts) > 0) {
				$msg = $this->playerManager->getInfo($whois) . " has joined the private channel. " . $altInfo->getAltsBlob(false, true);
			} else {
				$msg = $this->playerManager->getInfo($whois) . " has joined the private channel.";
			}
		} else {
			$msg = "$sender has joined the private channel.";
			if (count($altInfo->alts) > 0) {
				$msg .= " " . $altInfo->getAltsBlob(false, true);
			}
		}

		if ($this->settingManager->getBool("guest_relay")) {
			$this->chatBot->sendGuild($msg, true);
		}
		$this->chatBot->sendPrivate($msg, true);
	}
	
	/**
	 * @Event("leavePriv")
	 * @Description("Displays a message when a character leaves the private channel")
	 */
	public function leavePrivateChannelMessageEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$msg = "<highlight>$sender<end> has left the private channel.";

		if ($this->settingManager->getBool("guest_relay")) {
			$this->chatBot->sendGuild($msg, true);
		}
	}
	
	/**
	 * @Event("joinPriv")
	 * @Description("Updates the database when a character joins the private channel")
	 */
	public function joinPrivateChannelRecordEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		$this->onlineController->addPlayerToOnlineList(
			$sender,
			$this->chatBot->vars['guild'] . ' Guests',
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
		$msg = "";
		$msg = $this->onlineController->getOnlineList();
		$this->chatBot->sendTell($msg, $sender);
	}
	
	public function addUser(string $name, $autoInvite=true): string {
		$name = ucfirst(strtolower($name));
		$uid = $this->chatBot->get_uid($name);
		if ($this->chatBot->vars["name"] == $name) {
			return "You cannot add the bot as a member of itself.";
		} elseif (!$uid || $uid < 0) {
			return "Character <highlight>$name<end> does not exist.";
		}
		// always add in case they were removed from the buddy list for some reason
		$this->buddylistManager->add($name, 'member');
		$data = $this->db->query("SELECT * FROM members_<myname> WHERE `name` = ?", $name);
		if (count($data) !== 0) {
			return "<highlight>$name<end> is already a member of this bot.";
		}
		$this->db->exec("INSERT INTO members_<myname> (`name`, `autoinv`) VALUES (?, ?)", $name, $autoInvite);
		return "<highlight>$name<end> has been added as a member of this bot.";
	}
	
	public function removeUser(string $name): string {
		$name = ucfirst(strtolower($name));

		if (!$this->db->exec("DELETE FROM members_<myname> WHERE `name` = ?", $name)) {
			return "<highlight>$name<end> is not a member of this bot.";
		}
		$this->buddylistManager->remove($name, 'member');
		return "<highlight>$name<end> has been removed as a member of this bot.";
	}
}
