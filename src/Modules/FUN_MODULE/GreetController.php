<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Generator;
use Nadybot\Core\{

	AOChatEvent,
	Attributes as NCA,
	DB,
	ModuleInstance,
	Nadybot,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltsController;

use function Amp\delay;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
]
class GreetController extends ModuleInstance {
	public const PER_CHARACTER = "per-character";
	public const PER_MAIN = "per-main";
	public const PER_JOIN = "per-join";

	public const TELL = "via tell";
	public const PRIV_CHANNEL = "in the private channel";

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public FunController $fun;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public DB $db;

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

	/** Delay in seconds between joining and receiving the greeting */
	#[NCA\Setting\Number]
	public int $greetDelay = 1;

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
		yield delay($this->greetDelay * 1000);
		if (!$this->needsGreeting($event->sender)) {
			return;
		}
		$greeting = $this->fun->getFunItem("greeting", $event->sender);
		if ($this->greetLocation === self::PRIV_CHANNEL) {
			$this->chatBot->sendPrivate($greeting);
		} else if ($this->greetLocation === self::TELL) {
			$this->chatBot->sendTell($greeting, $event->sender);
		}
	}

	/** Determines if $character needs to be greeted */
	private function needsGreeting(string $character): bool {
		if ($this->greetFrequency === 0) {
			return false;
		}
		if ($this->greetPropability === 0) {
			return false;
		}
		$key = $character;
		if ($this->greetCountType === self::PER_MAIN) {
			$key = $this->altsController->getMainOf($character);
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
}
