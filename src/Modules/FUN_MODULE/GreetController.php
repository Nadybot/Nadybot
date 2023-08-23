<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Generator;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	Text,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltEvent;
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\ParamClass\PRemove;

use function Amp\delay;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "greeting",
		description: "Manage custom greeting messages",
		accessLevel: "mod",
	),
	NCA\DefineCommand(
		command: "greeting on/off",
		description: "Enable/Disable greeting messages for oneself",
		accessLevel: "member",
	),
]
class GreetController extends ModuleInstance {
	public const PER_CHARACTER = "per-character";
	public const PER_MAIN = "per-main";
	public const PER_JOIN = "per-join";

	public const TELL = "via tell";
	public const PRIV_CHANNEL = "in the private channel";

	public const TYPE = "greeting";
	public const TYPE_CUSTOM = "greeting-custom";
	public const PREF = "greeting";
	public const PREF_ON = "on";
	public const PREF_OFF = "off";

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public FunController $fun;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public Preferences $prefs;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** How often to consider the greet propability when someone joins */
	#[NCA\Setting\Number(
		options: [
			"off" => 0,
			"every time" => 1,
			"every second time" => 2,
			"every fifth time" => 5,
			"every tenth time" => 10,
		]
	)]
	public int $greetFrequency = 1;

	/** Propability that someone gets greeted when the greet frequency matches */
	#[NCA\Setting\Number(
		options: [
			"off" => 0,
			"10%" => 10,
			"20%" => 20,
			"30%" => 30,
			"40%" => 40,
			"50%" => 50,
			"60%" => 60,
			"70%" => 70,
			"80%" => 80,
			"90%" => 90,
			"100%" => 100,
		]
	)]
	public int $greetPropability = 0;

	/** How to count the greet frequency */
	#[NCA\Setting\Options(
		options: [
			self::PER_CHARACTER,
			self::PER_MAIN,
			self::PER_JOIN,
		]
	)]
	public string $greetCountType = self::PER_CHARACTER;

	/** Where to greet */
	#[NCA\Setting\Options(
		options: [
			self::TELL,
			self::PRIV_CHANNEL,
		]
	)]
	public string $greetLocation = self::PRIV_CHANNEL;

	/** Which greetings to use */
	#[NCA\Setting\Options(
		options: [
			"default only" => self::TYPE,
			"custom only" => self::TYPE_CUSTOM,
			"default+custom" => self::TYPE . "," . self::TYPE_CUSTOM,
		]
	)]
	public string $greetSource = self::TYPE . "," . self::TYPE_CUSTOM;

	/** Delay in seconds between joining and receiving the greeting */
	#[NCA\Setting\Number]
	public int $greetDelay = 1;

	/** @var array<string,int> */
	private static array $greetCount = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/greeting.csv");
	}

	#[NCA\Event(
		name: "joinpriv",
		description: "Greet joined players with a random text",
	)]
	public function sendRandomGreeting(AOChatEvent $event): Generator {
		if (!is_string($event->sender)) {
			return;
		}
		yield delay($this->greetDelay * 1000);
		if (!$this->needsGreeting($event->sender)) {
			return;
		}
		$greeting = $this->fun->getFunItem($this->greetSource, $event->sender);
		if ($this->greetLocation === self::PRIV_CHANNEL) {
			$this->chatBot->sendPrivate($greeting);
		} elseif ($this->greetLocation === self::TELL) {
			$this->chatBot->sendTell($greeting, $event->sender);
		}
	}

	#[NCA\HandlesCommand("greeting")]
	/** List all custom-greetings */
	public function listGreetings(CmdContext $context): void {
		$lines = $this->db->table("fun")
			->where("type", self::TYPE_CUSTOM)
			->asObj(Fun::class)
			->map(function (Fun $entry) use ($context): string {
				$delLink = $this->text->makeChatcmd(
					"remove",
					"/tell <myname> " . $context->getCommand() . " rem " . $entry->id
				);
				return "<tab>- [{$delLink}] {$entry->content}";
			});
		if ($lines->isEmpty()) {
			$context->reply(
				"No custom greeting defined. Use <highlight><symbol>".
				$context->getCommand() . " add &lt;greeting&gt;<end> to add one."
			);
			return;
		}
		$msg = $this->text->makeBlob(
			"Defined custom greetings",
			"<header2>Greetings<end>\n" . $lines->join("\n")
		);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("greeting")]
	/** Add a new custom greeting. Use *name* as a placeholder for the person who joined */
	public function addGreeting(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		string $greeting,
	): void {
		$fun = new Fun();
		$fun->type = self::TYPE_CUSTOM;
		$fun->content = $greeting;
		$id = $this->db->insert("fun", $fun);
		$context->reply("New greeting added as <highlight>#{$id}<end>.");
	}

	#[NCA\HandlesCommand("greeting")]
	/** Remove a custom greeting */
	public function delGreeting(
		CmdContext $context,
		PRemove $action,
		int $id,
	): void {
		$deleted = $this->db->table("fun")
			->where("type", self::TYPE_CUSTOM)
			->where("id", $id)
			->delete();
		if (!$deleted) {
			$context->reply("The greeting <highlight>#{$id}<end> doesn't exist.");
			return;
		}
		$context->reply("Greeting <highlight>#{$id}<end> deleted successfully.");
	}

	#[NCA\HandlesCommand("greeting on/off")]
	/** Enable greeting messages for you and your alts */
	public function enableGreetings(
		CmdContext $context,
		#[NCA\Str("on")] string $action,
	): void {
		$main = $this->altsController->getMainOf($context->char->name);
		$this->prefs->save($main, self::PREF, self::PREF_ON);
		$context->reply("Receiving greetings is now <on>enabled<end>.");
	}

	#[NCA\HandlesCommand("greeting on/off")]
	/** Disable greeting messages for you and your alts */
	public function disableGreetings(
		CmdContext $context,
		#[NCA\Str("off")] string $action,
	): void {
		$main = $this->altsController->getMainOf($context->char->name);
		$this->prefs->save($main, self::PREF, self::PREF_OFF);
		$context->reply("Receiving greetings is now <off>disabled<end>.");
	}

	/** Determines if $character needs to be greeted */
	private function needsGreeting(string $character): bool {
		if ($this->greetFrequency === 0) {
			return false;
		}
		if ($this->greetPropability === 0) {
			return false;
		}
		$main = $this->altsController->getMainOf($character);
		if ($this->prefs->get($main, self::PREF) === self::PREF_OFF) {
			return false;
		}

		$key = $character;
		if ($this->greetCountType === self::PER_MAIN) {
			$key = $main;
		} else {
			$key = "X";
		}
		if (!array_key_exists($key, self::$greetCount)) {
			self::$greetCount[$key] = 0;
		} else {
			self::$greetCount[$key]++;
		}

		if ((self::$greetCount[$key] % $this->greetFrequency) !== 0) {
			return false;
		}
		return random_int(1, 100) <= $this->greetPropability;
	}

	#[NCA\Event(
		name: "alt(newmain)",
		description: "Move greeting preferences to new main"
	)]
	public function moveGreetingPrefs(AltEvent $event): void {
		$oldSetting = $this->prefs->get($event->alt, self::PREF);
		if ($oldSetting === null) {
			return;
		}
		$this->prefs->save($event->main, self::PREF, $oldSetting);
		$this->prefs->delete($event->alt, self::PREF);
		$this->logger->notice("Moved greeting settings ({old}) from {from} to {to}.", [
			"old" => $oldSetting,
			"from" => $event->alt,
			"to" => $event->main,
		]);
	}
}
