<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
    AOChatEvent,
    AOChatPacket,
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
 *		command     = 'testtowerattack',
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
	 * @Matches("/^test$/i")
	 */
	public function testListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$files = $this->util->getFilesInDirectory($this->path);
		$count = count($files);
		sort($files);
		$blob = $this->text->makeChatcmd("All Tests", "/tell <myname> test all") . "\n";
		foreach ($files as $file) {
			$name = str_replace(".txt", "", $file);
			$blob .= $this->text->makeChatcmd($name, "/tell <myname> test $name") . "\n";
		}
		$msg = $this->text->makeBlob("Tests Available ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("test")
	 * @Matches("/^test all$/i")
	 */
	public function testAllCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = "msg";
		if ($this->settingManager->getBool('show_test_results')) {
			$mockSendto = $sendto;
		} else {
			$mockSendto = new MockCommandReply();
		}

		$files = $this->util->getFilesInDirectory($this->path);
		$starttime = time();
		$sendto->reply("Starting tests...");
		foreach ($files as $file) {
			$lines = file($this->path . $file, \FILE_IGNORE_NEW_LINES);
			$this->runTests($lines, $sender, $type, $mockSendto);
		}
		$time = $this->util->unixtimeToReadable(time() - $starttime);
		$sendto->reply("Finished tests. Time: $time");
	}
	
	/**
	 * @HandlesCommand("test")
	 * @Matches("/^test ([a-z0-9_-]+)$/i")
	 */
	public function testModuleCommand($message, $channel, $sender, $sendto, $args) {
		$file = $args[1] . ".txt";

		$type = "msg";
		if ($this->setting->show_test_results == 1) {
			$mockSendto = $sendto;
		} else {
			$mockSendto = new MockCommandReply();
			$mockSendto->logger = $this->logger;
		}
	
		$lines = file($this->path . $file, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			$sendto->reply("Could not find test <highlight>$file<end> to run.");
		} else {
			$starttime = time();
			$sendto->reply("Starting test $file...");
			$this->runTests($lines, $sender, $type, $mockSendto);
			$time = $this->util->unixtimeToReadable(time() - $starttime);
			$sendto->reply("Finished test $file. Time: $time");
		}
	}
	
	public function runTests(array $commands, string $sender, string $type, CommandReply $sendto): void {
		foreach ($commands as $line) {
			if ($line[0] == "!") {
				if ($this->settingManager->getBool('show_test_commands')) {
					$this->chatBot->sendTell($line, $sender);
				} else {
					$this->logger->log('INFO', $line);
				}
				$line = substr($line, 1);
				$this->commandManager->process($type, $line, $sender, $sendto);
			}
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
		$packet = new AOChatPacket("in", AOCP_LOGIN_OK, "");
		$packet->type = AOCP_GROUP_MESSAGE;
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
	 * @HandlesCommand("testtowervictory")
	 * @Matches("/^testtowervictory (Clan|Neutral|Omni) (.+) (Clan|Neutral|Omni) (.+) (.+)$/i")
	 */
	public function testTowerVictoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$eventObj = new AOChatEvent();
		$eventObj->sender = (string)0xFFFFFFFF;
		$eventObj->channel = "Tower Battle Outcome";
		$eventObj->message = "The $args[1] organization $args[2] attacked the $args[3] $args[4] at their base in $args[5]. The attackers won!!";
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
		$packet = new AOChatPacket("in", AOCP_LOGIN_OK, "");
		$packet->type = AOCP_GROUP_MESSAGE;
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
			$this->eventManager->callEventHandler($testEvent, $event);
			$sendto->reply("Event has been fired.");
		}
	}
	
	/**
	 * @HandlesCommand("testcloaklower")
	 * @Matches("/^testcloaklower$/i")
	 */
	public function testCloakLowerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gid = $this->chatBot->get_gid($this->chatBot->vars['my_guild']);
		if (!$gid) {
			$sendto->reply("Your bot must be in an org to test this.");
			return;
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"$sender turned the cloaking device in your city off.",
		];
		$packet = new AOChatPacket("in", AOCP_LOGIN_OK, "");
		$packet->type = AOCP_GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}
	
	/**
	 * @HandlesCommand("testcloakraise")
	 * @Matches("/^testcloakraise$/i")
	 */
	public function testCloakRaiseCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gid = $this->chatBot->get_gid($this->chatBot->vars['my_guild']);
		if (!$gid) {
			$sendto->reply("Your bot must be in an org to test this.");
			return;
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"$sender turned the cloaking device in your city on.",
		];
		$packet = new AOChatPacket("in", AOCP_LOGIN_OK, "");
		$packet->type = AOCP_GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}
	
	/**
	 * @HandlesCommand("msginfo")
	 * @Matches("/^msginfo (.+)$/i")
	 */
	public function msgInfoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = $args[1];

		$mockSendto = new MessageInfoCommandReply($sendto);
		$this->commandManager->process($channel, $cmd, $sender, $mockSendto);
	}
}
