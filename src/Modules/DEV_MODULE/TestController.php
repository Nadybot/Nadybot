<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use function Safe\{date};
use Amp\File\{FilesystemException};
use AO\Client\{SingleClient, WorkerPackage};
use AO\Package;
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	EventManager,
	Events\Event,
	Events\PrivateChannelMsgEvent,
	Exceptions\UserException,
	Filesystem,
	ModuleInstance,
	Modules\DISCORD\DiscordMessageIn,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PFaction,
	ParamClass\PPlayfield,
	ParamClass\PWord,
	Registry,
	SettingManager,
	Text,
	Types\Playfield,
	Util,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordMessageEvent;
use Psr\Log\LoggerInterface;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'test',
		accessLevel: 'admin',
		description: 'Test the bot commands',
	),
	NCA\DefineCommand(
		command: 'msginfo',
		accessLevel: 'guest',
		description: 'Show number of characters in response and the time it took to process',
	),
]
class TestController extends ModuleInstance {
	/** Show test commands as they are executed */
	#[NCA\Setting\Boolean]
	public bool $showTestCommands = false;

	/** Show test results from test commands */
	#[NCA\Setting\Boolean]
	public bool $showTestResults = false;

	public string $path = __DIR__ . '/tests/';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	/** @param string[] $commands */
	public function runTests(array $commands, CmdContext $context, string $logFile): void {
		foreach ($commands as $line) {
			if ($line[0] !== '!') {
				continue;
			}
			$testContext = clone $context;
			if ($this->showTestCommands) {
				$this->chatBot->sendTell($line, $context->char->name);
			} else {
				$this->logger->notice('{line}', ['line' => $line]);
				if (!$this->showTestResults) {
					$testContext->sendto = new MockCommandReply($line, $logFile);
					Registry::injectDependencies($testContext->sendto);
				}
			}
			$testContext->message = substr($line, 1);
			$this->commandManager->processCmd($testContext);
		}
	}

	/** Pretend that &lt;char&gt; joins your org */
	#[NCA\HandlesCommand('test')]
	public function testOrgJoinCommand(
		CmdContext $context,
		#[NCA\Str('orgjoin')] string $action,
		PCharacter $char
	): void {
		$this->sendOrgMsg(
			"{$context->char->name} invited {$char} to your organization."
		);
	}

	/** Pretend that &lt;char&gt; was kicked from your org */
	#[NCA\HandlesCommand('test')]
	public function testOrgKickCommand(
		CmdContext $context,
		#[NCA\Str('orgkick')] string $action,
		PCharacter $char
	): void {
		$this->sendOrgMsg(
			"{$context->char->name} kicked {$char} from your organization."
		);
	}

	/** Pretend that &lt;char&gt; left your org */
	#[NCA\HandlesCommand('test')]
	public function testOrgLeaveCommand(
		CmdContext $context,
		#[NCA\Str('orgleave')] string $action,
		PCharacter $char
	): void {
		$this->sendOrgMsg("{$char} just left your organization.");
	}

	/** Simulate your own Control Tower being attacked */
	#[NCA\HandlesCommand('test')]
	public function testOrgAttackCommand(
		CmdContext $context,
		#[NCA\Str('orgattack')] string $action,
		PCharacter $attacker,
		string $orgName
	): void {
		$this->sendOrgMsg(
			'The tower Control Tower - Neutral in Broken Shores was just '.
			"reduced to 75 % health by {$attacker} from the {$orgName} ".
			'organization!'
		);
	}

	/** Simulate your own Control Tower gets the defense shield disabled */
	#[NCA\HandlesCommand('test')]
	public function testOrgAttackPrepCommand(
		CmdContext $context,
		#[NCA\Str('orgattackprep')] string $action,
		PCharacter $attName,
		string $orgName
	): void {
		$this->sendOrgMsg(
			'Your controller tower in Southern Forest of Xzawkaz in '.
			'Deep Artery Valley has had its defense shield disabled by '.
			"{$attName} (clan).The attacker is a member of the ".
			"organization {$orgName}."
		);
	}

	/** Pretend &lt;att org&gt; won the attack against &lt;def org&gt; */
	#[NCA\HandlesCommand('test')]
	public function testTowerVictoryCommand(
		CmdContext $context,
		#[NCA\Str('towervictory')] string $action,
		PFaction $attFaction,
		string $attOrg,
		PFaction $defFaction,
		string $defOrg,
		PPlayfield $playfield
	): void {
		$pf = Playfield::tryByName($playfield());
		if (!isset($pf)) {
			$context->reply("There is no playfield <highlight>{$playfield}<end>.");
			return;
		}
		$this->sendTowerMsg(
			"The {$attFaction} organization {$attOrg} ".
			"attacked the {$defFaction} {$defOrg} at their base in ".
			"{$pf->long()}. The attackers won!!"
		);
	}

	/** Pretend &lt;launcher&gt; just launched an orbital strike */
	#[NCA\HandlesCommand('test')]
	public function testOSCommand(
		CmdContext $context,
		#[NCA\Str('os')] string $action,
		PCharacter $launcher
	): void {
		$this->sendOrgMsg(
			"Blammo! {$launcher} has launched an orbital attack!"
		);
	}

	/**
	 * Call the given event. Format is &lt;instance&gt;.&lt;method&gt;
	 *
	 * Note that the $eventObj will be null, so this typically only works for cron events
	 */
	#[NCA\HandlesCommand('test')]
	public function testEventCommand(
		CmdContext $context,
		#[NCA\Str('event')] string $action,
		string $event
	): void {
		[$instanceName, $methodName] = explode('.', $event);
		$instance = Registry::tryGetInstance($instanceName);
		if ($instance === null) {
			$context->reply("Instance <highlight>{$instanceName}<end> does not exist.");
		} elseif (!method_exists($instance, $methodName)) {
			$context->reply("Method <highlight>{$methodName}<end> does not exist on instance <highlight>{$instanceName}<end>.");
		} else {
			$testEvent = new class () extends Event {
				public function __construct() {
					$this->type = 'dummy';
				}
			};
			$this->eventManager->callEventHandler($testEvent, $event, []);
			$context->reply('Event has been fired.');
		}
	}

	/** Pretend you just lowered your city cloak */
	#[NCA\HandlesCommand('test')]
	public function testCloakLowerCommand(
		CmdContext $context,
		#[NCA\Str('cloaklower')] string $action
	): void {
		$orgGroup = $this->chatBot->getOrgGroup();
		if (!isset($orgGroup)) {
			$context->reply('Your bot must be in an org to test this.');
			return;
		}

		$this->chatBot->processPackage(
			new WorkerPackage(
				worker: $this->config->main->character,
				package: new Package\In\GroupMessage(
					groupId: $orgGroup->id,
					charId: 0xFF_FF_FF_FF,
					message: "{$context->char->name} turned the cloaking device in your city off.",
					extra: "\0",
				),
				client: $this->getWorker(),
			)
		);
	}

	/** Pretend you just raised your city cloak */
	#[NCA\HandlesCommand('test')]
	public function testCloakRaiseCommand(
		CmdContext $context,
		#[NCA\Str('cloakraise')] string $action
	): void {
		$orgGroup = $this->chatBot->getOrgGroup();
		if (!isset($orgGroup)) {
			$context->reply('Your bot must be in an org to test this.');
			return;
		}

		$this->chatBot->processPackage(
			new WorkerPackage(
				worker: $this->config->main->character,
				package: new Package\In\GroupMessage(
					groupId: $orgGroup->id,
					charId: 0xFF_FF_FF_FF,
					message: "{$context->char->name} turned the cloaking device in your city on.",
					extra: "\0",
				),
				client: $this->getWorker(),
			)
		);
	}

	/**
	 * See how many characters are contained in a command response
	 * and how long it took to process the command
	 */
	#[NCA\HandlesCommand('msginfo')]
	public function msgInfoCommand(CmdContext $context, string $cmd): void {
		if (!isset($context->sendto)) {
			return;
		}
		$context->message = $cmd;
		$context->sendto = new MessageInfoCommandReply($context->sendto);
		$this->commandManager->processCmd($context);
	}

	/** Receive a dummy message from a tradebot */
	#[NCA\HandlesCommand('test')]
	public function testTradebotMessageCommand(
		CmdContext $context,
		#[NCA\Str('tradebotmsg')] string $action
	): void {
		$tradebot = $this->settingManager->getString('tradebot') ?? 'Darknet';
		$eventObj = new PrivateChannelMsgEvent(
			sender: $tradebot,
			channel: $tradebot,
			message: "<font color='#89D2E8'>".
				"<font color='#FFCC00'>[General]</font> ".
				"<font color='#FF9900'>".
					'Does anyone have Alien Augmentation Device - Medical '.
					'to borrow for a minute please? will tip'.
				'</font> '.
				"<font color='#66CC00'>[<a  href='user://Bosnian'>Bosnian</a>]</font> ".
				"[<a href=\"text://<font color='#FFFF00'>Report/Ignore</font>".
				"<br><br><font color='#FFFFFF'>".
				"<font color='#00BFFF'>Bosnian</font> ".
				"(146/<font color='#00DE42'>9</font>) ".
				"<font color='#F79410'>Clan</font> Soldier<br><br>".
				"<a  href='chatcmd:///tell Darknet ignore add Bosnian'>Ignore player</a>".
				'<br><br>If you feel this message is inappropriate or does not belong on '.
				'this platform, please report it:<br>'.
				"<a  href='chatcmd:///tell Darknet report 264750 wrong channel'>".
					'Report wrong channel'.
				'</a><br>'.
				"<a  href='chatcmd:///tell Darknet report 264750 lockout timers'>".
					'Report using alts/friends to get around lockout timers'.
				'</a><br>'.
				"<a  href='chatcmd:///tell Darknet report 264750 offensive'>".
					'Report offensive content'.
				'</a><br>'.
				"<a  href='chatcmd:///tell Darknet report 264750 trolling'>".
					'Report trolling'.
				'</a><br>'.
				"<a  href='chatcmd:///tell Darknet report 264750 chat'>".
					'Report conversation/chat'.
				'</a><br>'.
				"<a  href='chatcmd:///tell Darknet report 264750 other'>".
					'Report for another reason'.
				'</a>">Report/Ignore</a>]',
		);

		try {
			$this->eventManager->fireEvent($eventObj);
		} catch (Exception $e) {
			// Ignore
		}
	}

	/** Receive a discord message from &lt;nick&gt; */
	#[NCA\HandlesCommand('test')]
	public function testDiscordMessageCommand(
		CmdContext $context,
		#[NCA\Str('discordpriv')] string $action,
		PCharacter $nick,
		string $content
	): void {
		$mapper = new ObjectMapperUsingReflection();
		$payload = [
			'type' => 0,
			'tts' => false,
			'timestamp' => '2021-05-09T06:44:07.143000+00:00',
			'referenced_message' => null,
			'pinned' => false,
			'nonce' => '840841547619500032',
			'mentions' => [],
			'mention_roles' => [],
			'mention_everyone' => false,
			'member' => [
				'roles' => ['731589704247410729'],
				'nick' => $nick(),
				'mute' => false,
				'joined_at' => '2020-07-11T16:46:42.205000+00:00',
				'hoisted_role' => null,
				'deaf' => false,
			],
			'id' => '840841548081528852',
			'flags' => 0,
			'embeds' => [],
			'edited_timestamp' => null,
			'content' => $content,
			'components' => [],
			'channel_id' => '731553649184211064',
			'author' => [
				'username' => $nick(),
				'public_flags' => 0,
				'id' => '356025105371103232',
				'discriminator' => '9062',
				'avatar' => '65fdc56a8ee53e6d197f1076f6b7813a',
			],
			'attachments' => [],
			'guild_id' => '731552006069551184',
		];
		$message = $mapper->hydrateObject(DiscordMessageIn::class, $payload);
		$event = new DiscordMessageEvent(
			message: $message->content,
			sender: $nick(),
			type: 'discordpriv',
			discord_message: $message,
			channel: '5361523761523761',
		);
		$this->eventManager->fireEvent($event);
	}

	/** Simulate &lt;char&gt; logging on */
	#[NCA\HandlesCommand('test')]
	public function testLogonCommand(
		CmdContext $context,
		#[NCA\Str('logon')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if ($uid === null) {
			$context->reply("The character <highlight>{$char}<end> does not exist.");
			return;
		}
		$this->chatBot->processPackage(
			new WorkerPackage(
				worker: $this->config->main->character,
				package: new Package\In\BuddyState(
					charId: $uid,
					online: true,
					extra: "\0"
				),
				client: $this->getWorker(),
			)
		);
	}

	/** Simulate &lt;char&gt; logging off */
	#[NCA\HandlesCommand('test')]
	public function testLogoffCommand(
		CmdContext $context,
		#[NCA\Str('logoff')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if ($uid === null) {
			$context->reply("The character <highlight>{$char}<end> does not exist.");
			return;
		}

		$this->chatBot->processPackage(
			new WorkerPackage(
				worker: $this->config->main->character,
				package: new Package\In\BuddyState(
					charId: $uid,
					online: false,
					extra: "\0"
				),
				client: $this->getWorker(),
			)
		);
	}

	/** Simulate &lt;char&gt; joining the private channel */
	#[NCA\HandlesCommand('test')]
	public function testJoinCommand(
		CmdContext $context,
		#[NCA\Str('join')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if ($uid === null) {
			$context->reply("The character <highlight>{$char}<end> does not exist.");
			return;
		}
		$channelId = $this->chatBot->char?->id;
		if ($channelId === null) {
			$context->reply('The bot is not connected to AO');
			return;
		}

		$this->chatBot->processPackage(
			new WorkerPackage(
				worker: $this->config->main->character,
				package: new Package\In\PrivateChannelClientJoined(channelId: $channelId, charId: $uid),
				client: $this->getWorker(),
			)
		);
	}

	/** Simulate &lt;char&gt; leaving the private channel */
	#[NCA\HandlesCommand('test')]
	public function testLeaveCommand(
		CmdContext $context,
		#[NCA\Str('leave')] string $action,
		PCharacter $char
	): void {
		$uid = $this->chatBot->getUid($char());
		if ($uid === null) {
			$context->reply("The character <highlight>{$char}<end> does not exist.");
			return;
		}
		if (null === ($channelId = $this->chatBot->char?->id)) {
			$context->reply('The bot is currently not connected.');
			return;
		}
		$this->chatBot->processPackage(
			new WorkerPackage(
				worker: $this->config->main->character,
				package: new Package\In\PrivateChannelClientLeft(channelId: $channelId, charId: $uid),
				client: $this->getWorker(),
			)
		);
	}

	/** Sleep for &lt;duration&gt; seconds. This can lead to lots of timeouts */
	#[NCA\HandlesCommand('test')]
	public function testSleepCommand(
		CmdContext $context,
		#[NCA\Str('sleep')] string $action,
		int $duration
	): void {
		/** @psalm-var int<0,max> $duration */
		sleep($duration);
	}

	/** Get a list of all tests the bot has */
	#[NCA\HandlesCommand('test')]
	public function testListCommand(CmdContext $context): void {
		$files = collect($this->util->getFilesInDirectory($this->path));
		$count = $files->count();
		$blob = Text::makeChatcmd('All Tests', '/tell <myname> test all') . "\n";
		foreach ($files as $file) {
			$name = str_replace('.txt', '', $file);
			$blob .= Text::makeChatcmd($name, "/tell <myname> test {$name}") . "\n";
		}
		$msg = $this->text->makeBlob("Tests Available ({$count})", $blob);
		$context->reply($msg);
	}

	/** Run absolutely all bot tests */
	#[NCA\HandlesCommand('test')]
	public function testAllCommand(
		CmdContext $context,
		#[NCA\Str('all')] string $action
	): void {
		$testContext = clone $context;

		$files = $this->fs->listFiles($this->path);
		$context->reply('Starting tests...');
		$logFile = $this->config->paths->data.
			'/tests-' . date('YmdHis', time()) . '.json';
		$testLines = [];
		foreach ($files as $file) {
			$data = $this->fs->read($this->path . $file);
			$lines = explode("\n", $data);
			$testLines = array_merge($testLines, $lines);
		}
		$this->runTests($testLines, $testContext, $logFile);
		$context->reply('Tests queued.');
	}

	/** Run all bot tests of a given file */
	#[NCA\HandlesCommand('test')]
	public function testModuleCommand(CmdContext $context, PWord $file): void {
		$file = "{$file}.txt";

		$testContext = clone $context;
		$testContext->permissionSet = 'msg';

		try {
			$lines = explode("\n", $this->fs->read($this->path . $file));
		} catch (FilesystemException) {
			$context->reply("Could not find test <highlight>{$file}<end> to run.");
			return;
		}
		$starttime = time();
		$logFile = $this->config->paths->data.
			'/tests-' . date('YmdHis', $starttime) . '.json';
		$context->reply("Starting test {$file}...");
		$this->runTests($lines, $testContext, $logFile);
		$time = Util::unixtimeToReadable(time() - $starttime);
		$context->reply("Finished test {$file}. Time: {$time}");
	}

	protected function sendGroupMsg(string $groupName, int $uid, string $message): void {
		$group = $this->chatBot->getGroupByName($groupName);
		if (!isset($group)) {
			throw new UserException("Your bot cannot read the \"{$groupName}\" channel.");
		}
		$this->chatBot->processPackage(
			new WorkerPackage(
				worker: $this->config->main->character,
				package: new Package\In\GroupMessage(
					groupId: $group->id,
					charId: $uid,
					message: $message,
					extra: "\0",
				),
				client: $this->getWorker(),
			)
		);
	}

	protected function sendOrgMsg(string $message): void {
		$this->sendGroupMsg('Org Msg', 0xFF_FF_FF_FF, $message);
	}

	protected function sendTowerMsg(string $message): void {
		$this->sendGroupMsg('All Towers', 0, $message);
	}

	private function getWorker(): SingleClient {
		$worker = $this->chatBot->aoClient->getBestWorker($this->config->main->character);
		if (!isset($worker)) {
			throw new UserException('Cannot find a usable AO client.');
		}
		return $worker;
	}
}
