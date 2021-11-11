<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Exception;
use Nadybot\Core\{
	AOChatEvent,
	AOChatPacket,
	CmdContext,
	CommandManager,
	CommandReply,
	Event,
	EventManager,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\Modules\DISCORD\DiscordMessageIn;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordMessageEvent;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'test',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testorgjoin',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testorgkick',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testorgleave',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testtowerattack',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testtowerattackorgless',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testtowervictory',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testtowerabandon',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testorgattack',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testorgattackprep',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testos',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testevent',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'testevent.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testcloaklower',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testcloakraise',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'msginfo',
 *		accessLevel = 'all',
 *		description = "Show number of characters in response and the time it took to process",
 *		help        = 'msginfo.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testtradebotmsg',
 *		accessLevel = 'admin',
 *		description = "Test a tradebot message",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testdiscordpriv',
 *		accessLevel = 'admin',
 *		description = "Test a discord channel message",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testlogon',
 *		accessLevel = 'admin',
 *		description = "Test a logon event",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testlogoff',
 *		accessLevel = 'admin',
 *		description = "Test a logoff event",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testjoin',
 *		accessLevel = 'admin',
 *		description = "Test a priv channel join event",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testleave',
 *		accessLevel = 'admin',
 *		description = "Test a priv channel leave event",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'testsleep',
 *		accessLevel = 'admin',
 *		description = "Sleep for a give time in seconds",
 *		help        = 'test.txt'
 *	)
 */
class TestController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Logger */
	public LoggerWrapper $logger;

	public string $path;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->path = __DIR__ . "/tests/";

		$this->settingManager->add(
			$this->moduleName,
			"show_test_commands",
			"Show test commands as they are executed",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"show_test_results",
			"Show test results from test commands",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
	}

	/**
	 * @HandlesCommand("test")
	 */
	public function testListCommand(CmdContext $context): void {
		$files = $this->util->getFilesInDirectory($this->path);
		$count = count($files);
		sort($files);
		$blob = $this->text->makeChatcmd("All Tests", "/tell <myname> test all") . "\n";
		foreach ($files as $file) {
			$name = str_replace(".txt", "", $file);
			$blob .= $this->text->makeChatcmd($name, "/tell <myname> test $name") . "\n";
		}
		$msg = $this->text->makeBlob("Tests Available ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("test")
	 */
	public function testAllCommand(CmdContext $context, string $action="all"): void {
		$testContext = clone $context;
		$testContext->channel = "msg";
		if (!$this->settingManager->getBool('show_test_results')) {
			$testContext->sendto = new MockCommandReply();
		}

		$files = $this->util->getFilesInDirectory($this->path);
		$starttime = time();
		$context->reply("Starting tests...");
		foreach ($files as $file) {
			$lines = file($this->path . $file, \FILE_IGNORE_NEW_LINES);
			$this->runTests($lines, $testContext);
		}
		$time = $this->util->unixtimeToReadable(time() - $starttime);
		$context->reply("Finished tests. Time: $time");
	}

	/**
	 * @HandlesCommand("test")
	 */
	public function testModuleCommand(CmdContext $context, string $file): void {
		$file = "{$file}.txt";

		$testContext = clone $context;
		$testContext->channel = "msg";
		if (!$this->settingManager->getBool('show_test_results')) {
			$testContext->sendto = new MockCommandReply();
			$testContext->sendto->logger = $this->logger;
		}

		$lines = file($this->path . $file, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			$context->reply("Could not find test <highlight>$file<end> to run.");
		} else {
			$starttime = time();
			$context->reply("Starting test $file...");
			$this->runTests($lines, $testContext);
			$time = $this->util->unixtimeToReadable(time() - $starttime);
			$context->reply("Finished test $file. Time: $time");
		}
	}

	public function runTests(array $commands, CmdContext $context): void {
		foreach ($commands as $line) {
			if ($line[0] !== "!") {
				continue;
			}
			if ($this->settingManager->getBool('show_test_commands')) {
				$this->chatBot->sendTell($line, $context->char->name);
			} else {
				$this->logger->log('DEBUG', $line);
			}
			$context->message = substr($line, 1);
			$this->commandManager->processCmd($context);
		}
	}

	/**
	 * @HandlesCommand("testorgjoin")
	 * @Matches("/^testorgjoin (.+)$/i")
	 */
	public function testOrgJoinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gid = $this->chatBot->get_gid('Org Msg');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'Org Msg';
			$this->chatBot->gid["Org Msg"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"$sender invited $args[1] to your organization.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testorgkick")
	 * @Matches("/^testorgkick (.+)$/i")
	 */
	public function testOrgKickCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gid = $this->chatBot->get_gid('Org Msg');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'Org Msg';
			$this->chatBot->gid["Org Msg"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"$sender kicked $args[1] from your organization.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testorgleave")
	 * @Matches("/^testorgleave (.+)$/i")
	 */
	public function testOrgLeaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gid = $this->chatBot->get_gid('Org Msg');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'Org Msg';
			$this->chatBot->gid["Org Msg"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"$args[1] just left your organization.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testtowerattack")
	 * @Matches("/^testtowerattack (clan|neutral|omni) (.+) (.+) (clan|neutral|omni) (.+) (.+) (\d+) (\d+)$/i")
	 */
	public function testTowerAttackCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$eventObj = new AOChatEvent();
		$eventObj->sender = -1;
		$eventObj->channel = "All Towers";
		$eventObj->message = "The $args[1] organization $args[2] just entered a state of war! $args[3] attacked the $args[4] organization $args[5]'s tower in $args[6] at location ($args[7],$args[8]).";
		$eventObj->type = 'towers';
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * @HandlesCommand("testtowerattackorgless")
	 * @Matches("/^testtowerattackorgless (.+) (clan|neutral|omni) (.+) (.+) (\d+) (\d+)$/i")
	 */
	public function testTowerAttackOrglessCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$eventObj = new AOChatEvent();
		$eventObj->sender = -1;
		$eventObj->channel = "All Towers";
		$eventObj->message = "{$args[1]} just attacked the ".strtolower($args[2])." organization $args[3]'s tower in $args[4] at location ($args[5], $args[6]).";
		$eventObj->type = 'towers';
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * @HandlesCommand("testtowerabandon")
	 * @Matches("/^testtowerabandon (clan|neutral|omni) ([^ ]+) (.+)$/i")
	 */
	public function testTowerAbandonCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args[1] = strtolower($args[1]);
		$eventObj = new AOChatEvent();
		$eventObj->sender = (string)0xFFFFFFFF;
		$eventObj->channel = "Tower Battle Outcome";
		$eventObj->message = "Notum Wars Update: The {$args[1]} organization {$args[2]} lost their base in {$args[3]}.";
		$eventObj->type = 'towers';
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * @HandlesCommand("testorgattack")
	 * @Matches("/^testorgattack ([^ ]+) (.+)$/i")
	 */
	public function testOrgAttackCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gid = $this->chatBot->get_gid('Org Msg');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'Org Msg';
			$this->chatBot->gid["Org Msg"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"The tower Control Tower - Neutral in Broken Shores was just reduced to 75 % health by {$args[1]} from the {$args[2]} organization!",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testorgattackprep")
	 * @Matches("/^testorgattackprep ([^ ]+) (.+)$/i")
	 */
	public function testOrgAttackPrepCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gid = $this->chatBot->get_gid('Org Msg');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'Org Msg';
			$this->chatBot->gid["Org Msg"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"Your controller tower in Southern Forest of Xzawkaz in Deep Artery Valley has had its defense shield disabled by {$args[1]} (clan).The attacker is a member of the organization {$args[2]}.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testtowervictory")
	 * @Matches("/^testtowervictory (Clan|Neutral|Omni) (.+) (Clan|Neutral|Omni) ([^ ]+) (.+)$/i")
	 */
	public function testTowerVictoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args[1] = ucfirst(strtolower($args[1]));
		$args[3] = ucfirst(strtolower($args[3]));
		$eventObj = new AOChatEvent();
		$eventObj->sender = (string)0xFFFFFFFF;
		$eventObj->channel = "Tower Battle Outcome";
		$eventObj->message = "The $args[1] organization $args[2] attacked the $args[3] $args[4] at their base in $args[5]. The attackers won!!";
		// $eventObj->message = "Notum Wars Update: The {$args[3]} organization {$args[4]} lost their base in {$args[5]}.";
		$eventObj->type = 'towers';
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * @HandlesCommand("testos")
	 * @Matches("/^testos (.+)$/i")
	 */
	public function testOSCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$launcher = ucfirst(strtolower($args[1]));

		$gid = $this->chatBot->get_gid('Org Msg');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'Org Msg';
			$this->chatBot->gid["Org Msg"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"Blammo! $launcher has launched an orbital attack!",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testevent")
	 * @Matches("/^testevent (.+)$/i")
	 */
	public function testEventCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$event = $args[1];

		[$instanceName, $methodName] = explode(".", $event);
		$instance = Registry::getInstance($instanceName);
		if ($instance === null) {
			$sendto->reply("Instance <highlight>$instanceName<end> does not exist.");
		} elseif (!method_exists($instance, $methodName)) {
			$sendto->reply("Method <highlight>$methodName<end> does not exist on instance <highlight>$instanceName<end>.");
		} else {
			$testEvent = new Event();
			$testEvent->type = 'dummy';
			$this->eventManager->callEventHandler($testEvent, $event, []);
			$sendto->reply("Event has been fired.");
		}
	}

	/**
	 * @HandlesCommand("testcloaklower")
	 * @Matches("/^testcloaklower$/i")
	 */
	public function testCloakLowerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		foreach ($this->chatBot->grp as $gid => $status) {
			if (ord(substr((string)$gid, 0, 1)) === 3) {
				break;
			}
		}
		if (!isset($gid)) {
			$sendto->reply("Your bot must be in an org to test this.");
			return;
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"$sender turned the cloaking device in your city off.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testcloakraise")
	 * @Matches("/^testcloakraise$/i")
	 */
	public function testCloakRaiseCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		foreach ($this->chatBot->grp as $gid => $status) {
			if (ord(substr((string)$gid, 0, 1)) === 3) {
				break;
			}
		}
		if (!isset($gid)) {
			$sendto->reply("Your bot must be in an org to test this.");
			return;
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"$sender turned the cloaking device in your city on.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("msginfo")
	 */
	public function msgInfoCommand(CmdContext $context, string $cmd): void {
		$context->message = $cmd;
		$context->sendto = new MessageInfoCommandReply($context);
		$this->commandManager->processCmd($context);
	}

	/**
	 * @HandlesCommand("testtradebotmsg")
	 * @Matches("/^testtradebotmsg$/i")
	 */
	public function testTradebotMessageCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$eventObj = new AOChatEvent();
		$tradebot = $this->settingManager->getString('tradebot') ?? "Darknet";
		$eventObj->sender = $tradebot;
		$eventObj->channel = $tradebot;
		$eventObj->message = "<font color='#89D2E8'>".
			"<font color='#FFCC00'>[General]</font> ".
			"<font color='#FF9900'>".
				"Does anyone have Alien Augmentation Device - Medical ".
				"to borrow for a minute please? will tip".
			"</font> ".
			"<font color='#66CC00'>[<a  href='user://Bosnian'>Bosnian</a>]</font> ".
			"[<a href=\"text://<font color='#FFFF00'>Report/Ignore</font>".
			"<br><br><font color='#FFFFFF'>".
			"<font color='#00BFFF'>Bosnian</font> ".
			"(146/<font color='#00DE42'>9</font>) ".
			"<font color='#F79410'>Clan</font> Soldier<br><br>".
			"<a  href='chatcmd:///tell Darknet ignore add Bosnian'>Ignore player</a>".
			"<br><br>If you feel this message is inappropriate or does not belong on ".
			"this platform, please report it:<br>".
			"<a  href='chatcmd:///tell Darknet report 264750 wrong channel'>".
				"Report wrong channel".
			"</a><br>".
			"<a  href='chatcmd:///tell Darknet report 264750 lockout timers'>".
				"Report using alts/friends to get around lockout timers".
			"</a><br>".
			"<a  href='chatcmd:///tell Darknet report 264750 offensive'>".
				"Report offensive content".
			"</a><br>".
			"<a  href='chatcmd:///tell Darknet report 264750 trolling'>".
				"Report trolling".
			"</a><br>".
			"<a  href='chatcmd:///tell Darknet report 264750 chat'>".
				"Report conversation/chat".
			"</a><br>".
			"<a  href='chatcmd:///tell Darknet report 264750 other'>".
				"Report for another reason".
			"</a>\">Report/Ignore</a>]";
		$eventObj->type = "extpriv";

		try {
			$this->eventManager->fireEvent($eventObj);
		} catch (Exception $e) {
			// Ignore
		}
	}

	/**
	 * @HandlesCommand("testdiscordpriv")
	 * @Matches("/^testdiscordpriv ([^ ]+) (.+)$/i")
	 */
	public function testDiscordMessageCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$event = new DiscordMessageEvent();
		$message = new DiscordMessageIn();
		$payload = json_decode(
			'{'.
				'"type":0,'.
				'"tts":false,'.
				'"timestamp":"2021-05-09T06:44:07.143000+00:00",'.
				'"referenced_message":null,'.
				'"pinned":false,'.
				'"nonce":"840841547619500032",'.
				'"mentions":[],'.
				'"mention_roles":[],'.
				'"mention_everyone":false,'.
				'"member":{'.
					'"roles":["731589704247410729"],'.
					'"nick":' . json_encode($args[1]) . ','.
					'"mute":false,'.
					'"joined_at":"2020-07-11T16:46:42.205000+00:00",'.
					'"hoisted_role":null,'.
					'"deaf":false'.
				'},'.
				'"id":"840841548081528852",'.
				'"flags":0,'.
				'"embeds":[],'.
				'"edited_timestamp":null,'.
				'"content":' . json_encode($args[2]) . ','.
				'"components":[],'.
				'"channel_id":"731553649184211064",'.
				'"author":{'.
					'"username":' . json_encode($args[1]) . ','.
					'"public_flags":0,'.
					'"id":"356025105371103232",'.
					'"discriminator":"9062",'.
					'"avatar":"65fdc56a8ee53e6d197f1076f6b7813a"'.
				'},'.
				'"attachments":[],'.
				'"guild_id":"731552006069551184"'.
			'}'
		);
		$message->fromJSON($payload);
		$event->discord_message = $message;
		$event->message = $message->content;
		$event->sender = $args[1];
		$event->type = "discordpriv";
		$event->discord_message = $message;
		$event->channel = "5361523761523761";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("testlogon")
	 * @Matches("/^testlogon (.+)$/i")
	 */
	public function testLogonCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$uid = $this->chatBot->get_uid(ucfirst(strtolower($args[1])));
		if ($uid === false) {
			$sendto->reply("The character <highlight>{$args[1]}<end> does not exist.");
			return;
		}
		$packet = new AOChatPacket("in", AOChatPacket::BUDDY_ADD, pack("NNn", $uid, 1, 0));

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testlogoff")
	 * @Matches("/^testlogoff (.+)$/i")
	 */
	public function testLogoffCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$uid = $this->chatBot->get_uid(ucfirst(strtolower($args[1])));
		if ($uid === false) {
			$sendto->reply("The character <highlight>{$args[1]}<end> does not exist.");
			return;
		}
		$packet = new AOChatPacket("in", AOChatPacket::BUDDY_ADD, pack("NNn", $uid, 0, 0));

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testjoin")
	 * @Matches("/^testjoin (.+)$/i")
	 */
	public function testJoinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$uid = $this->chatBot->get_uid(ucfirst(strtolower($args[1])));
		if ($uid === false) {
			$sendto->reply("The character <highlight>{$args[1]}<end> does not exist.");
			return;
		}
		$channel = $this->settingManager->getString("default_private_channel") ?? $this->chatBot->char->name;
		$channelUid = $this->chatBot->get_uid($channel);
		if ($channelUid === false) {
			$sendto->reply("Cannot determine this bot's private channel.");
			return;
		}
		$packet = new AOChatPacket("in", AOChatPacket::PRIVGRP_CLIJOIN, pack("NN", $channelUid, $uid));

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testleave")
	 * @Matches("/^testleave (.+)$/i")
	 */
	public function testLeaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$uid = $this->chatBot->get_uid(ucfirst(strtolower($args[1])));
		if ($uid === false) {
			$sendto->reply("The character <highlight>{$args[1]}<end> does not exist.");
			return;
		}
		$channel = $this->settingManager->getString("default_private_channel") ?? $this->chatBot->char->name;
		$channelUid = $this->chatBot->get_uid($channel);
		if ($channelUid === false) {
			$sendto->reply("Cannot determine this bot's private channel.");
			return;
		}
		$packet = new AOChatPacket("in", AOChatPacket::PRIVGRP_CLIPART, pack("NN", $channelUid, $uid));

		$this->chatBot->process_packet($packet);
	}

	/**
	 * @HandlesCommand("testsleep")
	 * @Matches("/^testsleep (\d+)$/i")
	 */
	public function testSleepCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		sleep((int)$args[1]);
	}
}
