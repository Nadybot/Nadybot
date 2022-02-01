<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\{
	AOChatEvent,
	AOChatPacket,
	CmdContext,
	CommandManager,
	ConfigFile,
	Event,
	EventManager,
	ModuleInstance,
	LoggerWrapper,
	Modules\BUDDYLIST\BuddylistController,
	Modules\DISCORD\DiscordMessageIn,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PFaction,
	ParamClass\PPlayfield,
	ParamClass\PTowerSite,
	Registry,
	SettingManager,
	Text,
	Timer,
	Util,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordMessageEvent;
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;
use Nadybot\Modules\TOWER_MODULE\TowerController;
use Safe\Exceptions\FilesystemException;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "test",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testorgjoin",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testorgkick",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testorgleave",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testtowerattack",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testtowerattackorgless",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testtowervictory",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testtowerabandon",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testorgattack",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testorgattackprep",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testos",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testevent",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "testevent.txt"
	),
	NCA\DefineCommand(
		command: "testcloaklower",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testcloakraise",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "msginfo",
		accessLevel: "all",
		description: "Show number of characters in response and the time it took to process",
		help: "msginfo.txt"
	),
	NCA\DefineCommand(
		command: "testtradebotmsg",
		accessLevel: "admin",
		description: "Test a tradebot message",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testdiscordpriv",
		accessLevel: "admin",
		description: "Test a discord channel message",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testlogon",
		accessLevel: "admin",
		description: "Test a logon event",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testlogoff",
		accessLevel: "admin",
		description: "Test a logoff event",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testjoin",
		accessLevel: "admin",
		description: "Test a priv channel join event",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testleave",
		accessLevel: "admin",
		description: "Test a priv channel leave event",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "testsleep",
		accessLevel: "admin",
		description: "Sleep for a give time in seconds",
		help: "test.txt"
	)
]
class TestController extends ModuleInstance {

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public BuddylistController $buddylistController;

	#[NCA\Inject]
	public PlayfieldController $playfieldController;

	#[NCA\Inject]
	public TowerController $towerController;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public string $path;

	#[NCA\Setup]
	public function setup(): void {
		$this->path = __DIR__ . "/tests/";

		$this->settingManager->add(
			module: $this->moduleName,
			name: "show_test_commands",
			description: "Show test commands as they are executed",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "show_test_results",
			description: "Show test results from test commands",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
	}

	#[NCA\HandlesCommand("test")]
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

	#[NCA\HandlesCommand("test")]
	public function testAllCommand(CmdContext $context, #[NCA\Str("all")] string $action): void {
		$testContext = clone $context;
		$testContext->sendto = new MockCommandReply("buddylist clear");
		$this->buddylistController->buddylistClearCommand($testContext, "clear");

		$files = $this->util->getFilesInDirectory($this->path);
		$context->reply("Starting tests...");
		$logFile = $this->config->dataFolder.
			"/tests-" . \Safe\date("YmdHis", time()) . ".json";
		$testLines = [];
		foreach ($files as $file) {
			$lines = \Safe\file($this->path . $file, \FILE_IGNORE_NEW_LINES);
			$testLines = array_merge($testLines, $lines);
		}
		$this->runTests($testLines, $testContext, $logFile);
		$context->reply("Tests queued.");
	}

	#[NCA\HandlesCommand("test")]
	public function testModuleCommand(CmdContext $context, string $file): void {
		$file = "{$file}.txt";

		$testContext = clone $context;
		$testContext->permissionSet = "msg";

		try {
			$lines = \Safe\file($this->path . $file, FILE_IGNORE_NEW_LINES);
		} catch (FilesystemException) {
			$context->reply("Could not find test <highlight>$file<end> to run.");
			return;
		}
		$starttime = time();
		$logFile = $this->config->dataFolder.
			"/tests-" . \Safe\date("YmdHis", $starttime) . ".json";
		$context->reply("Starting test $file...");
		$this->runTests($lines, $testContext, $logFile);
		$time = $this->util->unixtimeToReadable(time() - $starttime);
		$context->reply("Finished test $file. Time: $time");
	}

	/** @param string[] $commands */
	public function runTests(array $commands, CmdContext $context, string $logFile): void {
		do {
			$line = array_shift($commands);
		} while (isset($line) && $line[0] !== "!");
		if (!isset($line)) {
			return;
		}
		$testContext = clone $context;
		if ($this->settingManager->getBool('show_test_commands')) {
			$this->chatBot->sendTell($line, $context->char->name);
		} else {
			$this->logger->notice($line);
			$testContext->sendto = new MockCommandReply($line, $logFile);
			$testContext->sendto->logger = $this->logger;
		}
		$testContext->message = substr($line, 1);
		$this->commandManager->processCmd($testContext);
		$this->timer->callLater(0, [$this, __FUNCTION__], $commands, $context, $logFile);
	}

	protected function sendOrgMsg(string $message): void {
		$gid = $this->chatBot->get_gid('Org Msg');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'Org Msg';
			$this->chatBot->gid["Org Msg"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [$gid, (int)0xFFFFFFFF, $message];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("testorgjoin")]
	public function testOrgJoinCommand(CmdContext $context, PCharacter $char): void {
		$this->sendOrgMsg(
			"{$context->char->name} invited {$char} to your organization."
		);
	}

	#[NCA\HandlesCommand("testorgkick")]
	public function testOrgKickCommand(CmdContext $context, PCharacter $char): void {
		$this->sendOrgMsg(
			"{$context->char->name} kicked {$char} from your organization."
		);
	}

	#[NCA\HandlesCommand("testorgleave")]
	public function testOrgLeaveCommand(CmdContext $context, PCharacter $char): void {
		$this->sendOrgMsg("{$char} just left your organization.");
	}

	protected function getTowerLocationString(PTowerSite $site, string $format): ?string {
		$pf = $this->playfieldController->getPlayfieldByName($site->pf);
		if (!isset($pf)) {
			return null;
		}
		$tSite = $this->towerController->readTowerSiteById($pf->id, $site->site);
		if (!isset($tSite)) {
			return null;
		}
		return sprintf($format, $pf->long_name, $tSite->x_coord, $tSite->y_coord);
	}

	protected function sendTowerMsg(string $msg): void {
		$gid = $this->chatBot->get_gid('All Towers');
		if (!$gid) {
			$this->chatBot->gid["sicrit"] = 'All Towers';
			$this->chatBot->gid["All Towers"] = 'sicrit';
			$gid = 'sicrit';
		}
		$testArgs = [$gid, 0, $msg];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("testtowerattack")]
	public function testTowerAttackCommand(
		CmdContext $context,
		PFaction $attFaction,
		string $attOrg,
		PCharacter $attChar,
		PFaction $defFaction,
		string $defOrg,
		PTowerSite $site
	): void {
		$towerLocation = $this->getTowerLocationString($site, "%s at location (%d,%d)");
		if ($towerLocation === null) {
			$context->reply("The tower field <highlight>{$site->pf} {$site->site}<end> does not exist.");
			return;
		}
		$this->sendTowerMsg(
			"The {$attFaction} organization {$attOrg} just ".
			"entered a state of war! {$attChar} attacked the ".
			"{$defFaction} organization {$defOrg}'s tower in {$towerLocation}."
		);
	}

	#[NCA\HandlesCommand("testtowerattackorgless")]
	public function testTowerAttackOrglessCommand(
		CmdContext $context,
		PCharacter $attChar,
		PFaction $defFaction,
		string $defOrg,
		PTowerSite $site
	): void {
		$towerLocation = $this->getTowerLocationString($site, "%s at location (%d, %d)");
		if ($towerLocation === null) {
			$context->reply("The tower field <highlight>{$site->pf} {$site->site}<end> does not exist.");
			return;
		}
		$this->sendTowerMsg(
			"{$attChar} just attacked the {$defFaction} ".
			"organization {$defOrg}'s tower in {$towerLocation}."
		);
	}

	#[NCA\HandlesCommand("testtowerabandon")]
	public function testTowerAbandonCommand(
		CmdContext $context,
		PFaction $faction,
		string $orgName,
		PPlayfield $playfield
	): void {
		$pf = $this->playfieldController->getPlayfieldByName($playfield());
		if (!isset($pf)) {
			$context->reply("There is no playfield <highlight>{$playfield}<end>.");
			return;
		}
		$this->sendTowerMsg(
			"Notum Wars Update: The {$faction->lower} ".
			"organization {$orgName} lost their base in {$pf->long_name}."
		);
	}

	#[NCA\HandlesCommand("testorgattack")]
	public function testOrgAttackCommand(CmdContext $context, PCharacter $attName, string $orgName): void {
		$this->sendOrgMsg(
			"The tower Control Tower - Neutral in Broken Shores was just ".
			"reduced to 75 % health by {$attName} from the {$orgName} ".
			"organization!"
		);
	}

	#[NCA\HandlesCommand("testorgattackprep")]
	public function testOrgAttackPrepCommand(CmdContext $context, PCharacter $attName, string $orgName): void {
		$this->sendOrgMsg(
			"Your controller tower in Southern Forest of Xzawkaz in ".
			"Deep Artery Valley has had its defense shield disabled by ".
			"{$attName} (clan).The attacker is a member of the ".
			"organization {$orgName}."
		);
	}

	#[NCA\HandlesCommand("testtowervictory")]
	public function testTowerVictoryCommand(
		CmdContext $context,
		PFaction $attFaction,
		string $attOrg,
		PFaction $defFaction,
		string $defOrg,
		PPlayfield $playfield
	): void {
		$pf = $this->playfieldController->getPlayfieldByName($playfield());
		if (!isset($pf)) {
			$context->reply("There is no playfield <highlight>{$playfield}<end>.");
			return;
		}
		$this->sendTowerMsg(
			"The {$attFaction} organization {$attOrg} ".
			"attacked the {$defFaction} {$defOrg} at their base in ".
			"{$pf->long_name}. The attackers won!!"
		);
	}

	#[NCA\HandlesCommand("testos")]
	public function testOSCommand(CmdContext $context, PCharacter $launcher): void {
		$this->sendOrgMsg(
			"Blammo! {$launcher} has launched an orbital attack!"
		);
	}

	#[NCA\HandlesCommand("testevent")]
	public function testEventCommand(CmdContext $context, string $event): void {
		[$instanceName, $methodName] = explode(".", $event);
		$instance = Registry::getInstance($instanceName);
		if ($instance === null) {
			$context->reply("Instance <highlight>$instanceName<end> does not exist.");
		} elseif (!method_exists($instance, $methodName)) {
			$context->reply("Method <highlight>$methodName<end> does not exist on instance <highlight>$instanceName<end>.");
		} else {
			$testEvent = new Event();
			$testEvent->type = 'dummy';
			$this->eventManager->callEventHandler($testEvent, $event, []);
			$context->reply("Event has been fired.");
		}
	}

	#[NCA\HandlesCommand("testcloaklower")]
	public function testCloakLowerCommand(CmdContext $context): void {
		foreach ($this->chatBot->grp as $gid => $status) {
			if (ord(substr((string)$gid, 0, 1)) === 3) {
				break;
			}
		}
		if (!isset($gid)) {
			$context->reply("Your bot must be in an org to test this.");
			return;
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"{$context->char->name} turned the cloaking device in your city off.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("testcloakraise")]
	public function testCloakRaiseCommand(CmdContext $context): void {
		foreach ($this->chatBot->grp as $gid => $status) {
			if (ord(substr((string)$gid, 0, 1)) === 3) {
				break;
			}
		}
		if (!isset($gid)) {
			$context->reply("Your bot must be in an org to test this.");
			return;
		}
		$testArgs = [
			$gid,
			(int)0xFFFFFFFF,
			"{$context->char->name} turned the cloaking device in your city on.",
		];
		$packet = new AOChatPacket("in", AOChatPacket::LOGIN_OK, "");
		$packet->type = AOChatPacket::GROUP_MESSAGE;
		$packet->args = $testArgs;

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("msginfo")]
	public function msgInfoCommand(CmdContext $context, string $cmd): void {
		$context->message = $cmd;
		$context->sendto = new MessageInfoCommandReply($context);
		$this->commandManager->processCmd($context);
	}

	#[NCA\HandlesCommand("testtradebotmsg")]
	public function testTradebotMessageCommand(CmdContext $context): void {
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

	#[NCA\HandlesCommand("testdiscordpriv")]
	public function testDiscordMessageCommand(CmdContext $context, PCharacter $nick, string $content): void {
		$event = new DiscordMessageEvent();
		$message = new DiscordMessageIn();
		$payload = \Safe\json_decode(
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
					'"nick":' . \Safe\json_encode($nick()) . ','.
					'"mute":false,'.
					'"joined_at":"2020-07-11T16:46:42.205000+00:00",'.
					'"hoisted_role":null,'.
					'"deaf":false'.
				'},'.
				'"id":"840841548081528852",'.
				'"flags":0,'.
				'"embeds":[],'.
				'"edited_timestamp":null,'.
				'"content":' . \Safe\json_encode($content) . ','.
				'"components":[],'.
				'"channel_id":"731553649184211064",'.
				'"author":{'.
					'"username":' . \Safe\json_encode($nick()) . ','.
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
		$event->sender = $nick();
		$event->type = "discordpriv";
		$event->discord_message = $message;
		$event->channel = "5361523761523761";
		$this->eventManager->fireEvent($event);
	}

	#[NCA\HandlesCommand("testlogon")]
	public function testLogonCommand(CmdContext $context, PCharacter $who): void {
		$uid = $this->chatBot->get_uid($who());
		if ($uid === false) {
			$context->reply("The character <highlight>{$who}<end> does not exist.");
			return;
		}
		$packet = new AOChatPacket("in", AOChatPacket::BUDDY_ADD, \Safe\pack("NNn", $uid, 1, 0));

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("testlogoff")]
	public function testLogoffCommand(CmdContext $context, PCharacter $who): void {
		$uid = $this->chatBot->get_uid($who());
		if ($uid === false) {
			$context->reply("The character <highlight>{$who}<end> does not exist.");
			return;
		}
		$packet = new AOChatPacket("in", AOChatPacket::BUDDY_ADD, \Safe\pack("NNn", $uid, 0, 0));

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("testjoin")]
	public function testJoinCommand(CmdContext $context, PCharacter $who): void {
		$uid = $this->chatBot->get_uid($who());
		if ($uid === false) {
			$context->reply("The character <highlight>{$who}<end> does not exist.");
			return;
		}
		$channelUid = $this->chatBot->char->id;
		$packet = new AOChatPacket("in", AOChatPacket::PRIVGRP_CLIJOIN, \Safe\pack("NN", $channelUid, $uid));

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("testleave")]
	public function testLeaveCommand(CmdContext $context, PCharacter $who): void {
		$uid = $this->chatBot->get_uid($who());
		if ($uid === false) {
			$context->reply("The character <highlight>{$who}<end> does not exist.");
			return;
		}
		$channelUid = $this->chatBot->char->id;
		$packet = new AOChatPacket("in", AOChatPacket::PRIVGRP_CLIPART, \Safe\pack("NN", $channelUid, $uid));

		$this->chatBot->process_packet($packet);
	}

	#[NCA\HandlesCommand("testsleep")]
	public function testSleepCommand(CmdContext $context, int $duration): void {
		\Safe\sleep($duration);
	}
}
