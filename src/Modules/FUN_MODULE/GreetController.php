<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use function Amp\delay;
use Nadybot\Core\Events\JoinMyPrivEvent;
use Nadybot\Core\Modules\ALTS\{AltNewMainEvent, AltsController};

use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\ParamClass\{PRemove, PUuid};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	Events\LogonEvent,
	ModuleInstance,
	Nadybot,
	Text,
};
use Psr\Log\LoggerInterface;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'greeting',
		description: 'Manage custom greeting messages',
		accessLevel: 'mod',
	),
	NCA\DefineCommand(
		command: 'greeting on/off',
		description: 'Enable/Disable greeting messages for oneself',
		accessLevel: 'member',
	),
]
class GreetController extends ModuleInstance {
	public const PER_CHARACTER = 'per-character';
	public const PER_MAIN = 'per-main';
	public const PER_JOIN = 'per-join';

	public const TELL = 'tell';
	public const SOURCE_CHANNEL = 'source';

	public const TYPE = 'greeting';
	public const TYPE_CUSTOM = 'greeting-custom';
	public const PREF = 'greeting';
	public const PREF_ON = 'on';
	public const PREF_OFF = 'off';

	/** How often to consider the greet probability when someone joins */
	#[NCA\Setting\Number(
		options: [
			'off' => 0,
			'every time' => 1,
			'every second time' => 2,
			'every fifth time' => 5,
			'every tenth time' => 10,
		]
	)]
	public int $greetFrequency = 1;

	/** Probability that someone gets greeted when the greet frequency matches */
	#[NCA\Setting\Number(
		options: [
			'off' => 0,
			'10%' => 10,
			'20%' => 20,
			'30%' => 30,
			'40%' => 40,
			'50%' => 50,
			'60%' => 60,
			'70%' => 70,
			'80%' => 80,
			'90%' => 90,
			'100%' => 100,
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
			'via tell' => self::TELL,
			'org/private chat' => self::SOURCE_CHANNEL,
		]
	)]
	public string $greetLocation = self::SOURCE_CHANNEL;

	/** Which greetings to use */
	#[NCA\Setting\Options(
		options: [
			'default only' => self::TYPE,
			'custom only' => self::TYPE_CUSTOM,
			'default+custom' => self::TYPE . ',' . self::TYPE_CUSTOM,
		]
	)]
	public string $greetSource = self::TYPE . ',' . self::TYPE_CUSTOM;

	/** Delay in seconds between joining and receiving the greeting */
	#[NCA\Setting\Number]
	public int $greetDelay = 1;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private FunController $fun;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private Preferences $prefs;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	/** @var array<string,int> */
	private static array $greetCount = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/greeting.csv');
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Greet players joining the private channel',
	)]
	public function sendRandomJoinGreeting(JoinMyPrivEvent $event): void {
		if (!$this->needsGreeting($event->sender)) {
			return;
		}
		delay($this->greetDelay);

		$greeting = $this->getMatchingGreeting($event->sender);
		if (!isset($greeting)) {
			return;
		}
		$msg = $this->fun->renderPlaceholders($greeting, $event->sender);
		if ($this->greetLocation === self::SOURCE_CHANNEL) {
			$this->chatBot->sendPrivate($msg);
		} elseif ($this->greetLocation === self::TELL) {
			$this->chatBot->sendTell($msg, $event->sender);
		}
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Greet org members logging on'
	)]
	public function sendRandomLogonGreeting(LogonEvent $event): void {
		$sender = $event->sender;
		if (!isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
			|| $event->wasOnline !== false) {
			return;
		}
		if (!$this->needsGreeting($sender)) {
			return;
		}
		delay($this->greetDelay);
		$greeting = $this->fun->getFunItem($this->greetSource, $sender);
		if ($this->greetLocation === self::SOURCE_CHANNEL) {
			$this->chatBot->sendGuild($greeting);
		} elseif ($this->greetLocation === self::TELL) {
			$this->chatBot->sendTell($greeting, $sender);
		}
	}

	#[NCA\HandlesCommand('greeting')]
	/** List all custom-greetings */
	public function listGreetings(CmdContext $context): void {
		$lines = $this->db->table(Fun::getTable())
			->where('type', self::TYPE_CUSTOM)
			->asObj(Fun::class)
			->map(static function (Fun $entry) use ($context): string {
				$delLink = Text::makeChatcmd(
					'remove',
					'/tell <myname> ' . $context->getCommand() . ' rem ' . $entry->id
				);
				return "<tab>- [{$delLink}] {$entry->content}";
			});
		if ($lines->isEmpty()) {
			$context->reply(
				'No custom greeting defined. Use <highlight><symbol>'.
				$context->getCommand() . ' add &lt;greeting&gt;<end> to add one.'
			);
			return;
		}
		$msg = $this->text->makeBlob(
			'Defined custom greetings',
			"<header2>Greetings<end>\n" . $lines->join("\n")
		);
		$context->reply($msg);
	}

	/**
	 * Add a new custom greeting. Use *name* as a placeholder for the person who joined.
	 *
	 * If the first word is a pair in the form key=value, then the greeting will only
	 * be used if they match. Possible keys are: name, main, prof, gender, breed, faction
	 */
	#[NCA\HandlesCommand('greeting')]
	#[NCA\Help\Example(command: 'greeting add Welcome to the party, *name*!')]
	#[NCA\Help\Example(command: "greeting add prof=doc What's up, doc?")]
	#[NCA\Help\Example(command: 'greeting add main=Nady You again, *name*?')]
	public function addGreeting(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		string $greeting,
	): void {
		$fun = new Fun(
			type: self::TYPE_CUSTOM,
			content: $greeting,
		);
		$this->db->insert($fun);
		$context->reply("New greeting added as <highlight>{$fun->id}<end>.");
	}

	#[NCA\HandlesCommand('greeting')]
	/** Remove a custom greeting */
	public function delGreeting(
		CmdContext $context,
		PRemove $action,
		PUuid $id,
	): void {
		$id = $id();
		$deleted = $this->db->table(Fun::getTable())
			->where('type', self::TYPE_CUSTOM)
			->where('id', $id)
			->delete();
		if (!$deleted) {
			$context->reply("The greeting <highlight>{$id}<end> doesn't exist.");
			return;
		}
		$context->reply("Greeting <highlight>{$id}<end> deleted successfully.");
	}

	#[NCA\HandlesCommand('greeting on/off')]
	/** Enable greeting messages for you and your alts */
	public function enableGreetings(
		CmdContext $context,
		#[NCA\Str('on')] string $action,
	): void {
		$main = $this->altsController->getMainOf($context->char->name);
		$this->prefs->save($main, self::PREF, self::PREF_ON);
		$context->reply('Receiving greetings is now <on>enabled<end>.');
	}

	#[NCA\HandlesCommand('greeting on/off')]
	/** Disable greeting messages for you and your alts */
	public function disableGreetings(
		CmdContext $context,
		#[NCA\Str('off')] string $action,
	): void {
		$main = $this->altsController->getMainOf($context->char->name);
		$this->prefs->save($main, self::PREF, self::PREF_OFF);
		$context->reply('Receiving greetings is now <off>disabled<end>.');
	}

	#[NCA\Event(
		name: AltNewMainEvent::EVENT_MASK,
		description: 'Move greeting preferences to new main'
	)]
	public function moveGreetingPrefs(AltNewMainEvent $event): void {
		$oldSetting = $this->prefs->get($event->alt, self::PREF);
		if ($oldSetting === null) {
			return;
		}
		$this->prefs->save($event->main, self::PREF, $oldSetting);
		$this->prefs->delete($event->alt, self::PREF);
		$this->logger->notice('Moved greeting settings ({old}) from {from} to {to}.', [
			'old' => $oldSetting,
			'from' => $event->alt,
			'to' => $event->main,
		]);
	}

	/**
	 * Check if the given greeting-check applies to the player
	 *
	 * @param string $token  The token to check (main, name, prof)
	 * @param string $value  The value to check against
	 * @param string $target The name of the character to check against
	 */
	protected function matchesGreetingCheck(string $token, string $value, string $target): bool {
		switch ($token) {
			case 'main':
				return $this->altsController->getMainOf($target) === ucfirst(strtolower($value));
			case 'name':
			case 'char':
			case 'charname':
			case 'character':
				return $target === ucfirst(strtolower($value));
		}

		$player = $this->playerManager->byName($target);
		if (!isset($player)) {
			return false;
		}
		return match ($token) {
			'prof','profession' => $player->profession?->is($value) ?? false,
			'faction','side' => strtolower($player->faction->value) === strtolower($value),
			'gender','sex' => strtolower($player->gender) === strtolower($value),
			'race','breed' => strtolower($player->breed) === strtolower($value),
			default => true,
		};
	}

	/**
	 * Check if a given greeting applies to a given target
	 *
	 * @param string $target   The name of the person being greeted
	 * @param Fun    $greeting The Fun object with the greeting
	 *
	 * @return ?string Either the greeting, or null if it doesn't apply
	 */
	private function greetingFits(string $target, Fun $greeting): ?string {
		$parts = explode(' ', $greeting->content, 2);
		if (count($parts) < 2) {
			return $greeting->content;
		}
		$tokens = explode('=', $parts[0], 2);
		if (count($tokens) < 2) {
			return $greeting->content;
		}

		$matches = $this->matchesGreetingCheck($tokens[0], $tokens[1], $target);
		if ($matches) {
			return $parts[1];
		}
		return null;
	}

	/**
	 * Get a matching greeting for a given target
	 *
	 * @param string $target The name of the person being greeted
	 *
	 * @return ?string A matching greeting, or null;
	 */
	private function getMatchingGreeting(string $target): ?string {
		$data = $this->db->table(Fun::getTable())
			->whereIn('type', explode(',', $this->greetSource))
			->asObjArr(Fun::class);
		while (count($data) > 0) {
			$key = array_rand($data, 1);

			$greeting = $this->greetingFits($target, $data[$key]);
			if (isset($greeting)) {
				return $greeting;
			}
			unset($data[$key]);
		}
		return null;
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
			$key = 'X';
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
