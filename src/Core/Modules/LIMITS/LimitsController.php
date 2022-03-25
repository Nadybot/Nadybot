<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS;

use Nadybot\Core\{
	Attributes as NCA,
	AccessManager,
	CmdEvent,
	CommandHandler,
	ConfigFile,
	DBSchema\Audit,
	DBSchema\Player,
	LoggerWrapper,
	MessageHub,
	ModuleInstance,
	Modules\BAN\BanController,
	Modules\CONFIG\ConfigController,
	Nadybot,
	Routing\RoutableMessage,
	Routing\Source,
	Timer,
	Util,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\{
	PlayerHistory,
	PlayerHistoryData,
	PlayerHistoryManager,
	PlayerManager,
};

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class LimitsController extends ModuleInstance {
	public const ALL = 3;
	public const FAILURE = 2;
	public const SUCCESS = 1;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public PlayerHistoryManager $playerHistoryManager;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public RateIgnoreController $rateIgnoreController;

	#[NCA\Inject]
	public ConfigController $configController;

	#[NCA\Inject]
	public BanController $banController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Minimum level required to send tell to bot */
	#[NCA\Setting\Number(options: [0, 10, 50, 100, 150, 190, 205, 215])]
	public int $tellReqLvl = 0;

	/** Faction required to send tell to bot */
	#[NCA\Setting\Options(options: ["all", "Omni", "Neutral", "Clan", "not Omni", "not Neutral", "not Clan"])]
	public string $tellReqFaction = "all";

	/** Minimum age of player to send tell to bot */
	#[NCA\Setting\Time(
		options: ["1s", "7days", "14days", "1month", "2months", "6months", "1year", "2years"],
		help: 'limits.txt'
	)]
	public int $tellMinPlayerAge = 1;

	/** How to show error messages when limit requirements are not met? */
	#[NCA\Setting\Options(options: [
		'Specific' => 2,
		'Generic' => 1,
		'None' => 0,
	])]
	public int $tellErrorMsgType = 2;

	/** Ratelimit: Which commands to account for? */
	#[NCA\Setting\Options(options: [
		'All' => 3,
		'Only errors/denied' => 2,
		'Only successes' => 1,
		'None' => 0,
	])]
	public int $limitsCmdType = 0;

	/** Ratelimit: Which time window to check? */
	#[NCA\Setting\Options(options: [
		'5 secs' => 5,
		'10 secs' => 10,
		'30 secs' => 30,
		'1 min' => 60,
	])]
	public int $limitsWindow = 5;

	/** Ratelimit: How many commands per time window trigger actions? */
	#[NCA\Setting\Number(options: ["off" => 0, 2, 3, 4, 5, 6, 7, 8, 9, 10])]
	public int $limitsThreshold = 5;

	/** Ratelimit: Action when players exceed the allowed command rate */
	#[NCA\Setting\Options(options: [
		"Kick" => 1,
		"Temp. ban" => 2,
		"Kick+Temp. ban" => 3,
		"Temp. ignore" => 4,
		"Kick+Temp. ignore" => 5,
	])]
	public int $limitsOverrateAction = 4;

	/** Ratelimit: How long to temporarily ban or ignore? */
	#[NCA\Setting\Time(options: ["1m", "2m", "5m", "10m", "30m", "1h", "6h"])]
	public int $limitsIgnoreDuration = 300;

	/** Ratelimit: Ignore ratelimit for everyone of this rank or higher */
	#[NCA\Setting\Rank] public string $limitsExemptRank = "mod";

	/** @var array<string,int[]> */
	public array $limitBucket = [];

	/** @var array<string,int> */
	public array $ignoreList = [];

	/**
	 * Check if this is a command that doesn't fall under any limits
	 * Reason is that some command should always be allowed to be
	 * executed, regardless of your access rights or faction/level
	 * @param string $message The command including parameters
	 * @return bool true if limits are ignored, erlse false
	 */
	public function commandIgnoresLimits(string $message): bool {
		if (strcasecmp($message, "about") === 0) {
			return true;
		}
		if (preg_match("/^alt(decline|validate)\s+([a-z0-9-]+)$/i", $message)) {
			return true;
		}
		return false;
	}

	/**
	 * Check if $sender is allowed to send $message
	 * @phpstan-param callable(mixed...):mixed $callback
	 * @psalm-param callable(mixed...) $callback
	 */
	public function checkAndExecute(string $sender, string $message, callable $callback, mixed ...$args): void {
		if (
			$this->commandIgnoresLimits($message)
			|| $this->rateIgnoreController->check($sender)
			// if access level is at least member, skip checks
			|| $this->accessManager->checkAccess($sender, 'member')
		) {
			$callback(...$args);
			return;
		}
		$this->checkAccessError(
			$sender,
			function(string $msg) use ($sender, $message): void {
				$this->handleAccessError($sender, $message, $msg);
			},
			$callback,
			...$args
		);
	}

	public function handleAccessError(string $sender, string $message, string $msg): void {
		$this->logger->notice("$sender denied access to bot due to: $msg");

		$this->handleLimitCheckFail($msg, $sender);

		$cmd = explode(' ', $message, 2)[0];
		$cmd = strtolower($cmd);

		$r = new RoutableMessage("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end> due to limit checks.");
		$r->appendPath(new Source(Source::SYSTEM, "access-denied"));
		$this->messageHub->handle($r);
	}

	/**
	 * React to a $sender being denied to send $msg to us
	 */
	public function handleLimitCheckFail(string $msg, string $sender): void {
		if ($this->tellErrorMsgType === 2) {
			$this->chatBot->sendMassTell($msg, $sender);
		} elseif ($this->tellErrorMsgType === 1) {
			$msg = "Error! You do not have access to this bot.";
			$this->chatBot->sendMassTell($msg, $sender);
		}
	}

	/**
	 * @phpstan-param callable(string):void $errorHandler
	 * @psalm-param callable(string):void $errorHandler
	 * @phpstan-param callable(mixed...):mixed $successHandler
	 * @psalm-param callable(mixed...) $successHandler
	 */
	protected function handleLevelAndFactionRequirements(?Player $whois, callable $errorHandler, callable $successHandler, mixed ...$args): void {
		if ($whois === null) {
			$errorHandler("Error! Unable to get your character info for limit checks. Please try again later.");
			return;
		}
		$tellReqFaction = $this->tellReqFaction;
		$tellReqLevel = $this->tellReqLvl;

		// check minlvl
		if ($tellReqLevel > 0 && $tellReqLevel > $whois->level) {
			$errorHandler("Error! You must be at least level <highlight>$tellReqLevel<end>.");
			return;
		}

		// check faction limit
		if (
			in_array($tellReqFaction, ["Omni", "Clan", "Neutral"])
			&& $tellReqFaction !== $whois->faction
		) {
			$errorHandler("Error! You must be <".strtolower($tellReqFaction).">$tellReqFaction<end>.");
			return;
		}
		if (in_array($tellReqFaction, ["not Omni", "not Clan", "not Neutral"])) {
			$tmp = explode(" ", $tellReqFaction);
			if ($tmp[1] === $whois->faction) {
				$errorHandler("Error! You must not be <".strtolower($tmp[1]).">{$tmp[1]}<end>.");
				return;
			}
		}
		$successHandler(...$args);
	}

	/**
	 * Check if $sender is allowed to run commands on the bot
	 *
	 * @phpstan-param callable(string):void $errorHandler
	 * @psalm-param callable(string):void $errorHandler
	 * @phpstan-param callable(mixed...):mixed $successHandler
	 * @psalm-param callable(mixed...) $successHandler
	 */
	public function checkAccessError(string $sender, callable $errorHandler, callable $successHandler, mixed ...$args): void {
		$minAgeCheck = function() use ($sender, $errorHandler, $successHandler, $args): void {
			if ($this->tellMinPlayerAge <= 1) {
				$successHandler(...$args);
				return;
			}
			$this->playerHistoryManager->asyncLookup(
				$sender,
				$this->config->dimension,
				/** @param mixed $args */
				function(?PlayerHistory $history, callable $errorHandler, callable $successHandler, ...$args): void {
					$this->handleMinAgeRequirements($history, $errorHandler, $successHandler, ...$args);
				},
				$errorHandler,
				$successHandler,
				...$args
			);
		};
		$tellReqFaction = $this->tellReqFaction;
		$tellReqLevel = $this->tellReqLvl;
		if ($tellReqLevel > 0 || $tellReqFaction !== "all") {
			// get player info which is needed for following checks
			$this->playerManager->getByNameAsync(
				function(?Player $player) use ($errorHandler, $minAgeCheck): void {
					$this->handleLevelAndFactionRequirements(
						$player,
						$errorHandler,
						$minAgeCheck
					);
				},
				$sender
			);
			return;
		}
		$minAgeCheck();
	}

	/**
	 * @phpstan-param callable(string):void $errorHandler
	 * @psalm-param callable(string):void $errorHandler
	 * @phpstan-param callable(mixed...):mixed $successHandler
	 * @psalm-param callable(mixed...) $successHandler
	 */
	protected function handleMinAgeRequirements(?PlayerHistory $history, callable $errorHandler, callable $successHandler, mixed ...$args): void {
		if ($history === null) {
			$errorHandler("Error! Unable to get your character history for limit checks. Please try again later.");
			return;
		}
		$minAge = time() - $this->tellMinPlayerAge;
		/** @var PlayerHistoryData */
		$entry = array_pop($history->data);
		// TODO check for rename

		if ($entry->last_changed->getTimestamp() > $minAge) {
			$timeString = $this->util->unixtimeToReadable($this->tellMinPlayerAge);
			$errorHandler("Error! You must be at least <highlight>$timeString<end> old.");
			return;
		}

		$successHandler(...$args);
	}

	#[NCA\Event(
		name: "command(*)",
		description: "Enforce rate limits"
	)]
	public function accountCommandExecution(CmdEvent $event): void {
		if ($event->cmdHandler && !$this->commandHandlerCounts($event->cmdHandler)) {
			return;
		}
		$toCount = $this->limitsCmdType;
		$isSuccess = in_array($event->type, ["command(success)"]);
		$isFailure = !in_array($event->type, ["command(success)"]);
		if (($isSuccess && ($toCount & static::SUCCESS) === 0)
			|| ($isFailure && ($toCount & static::FAILURE) === 0)) {
			return;
		}
		$now = time();
		$this->limitBucket[(string)$event->sender] ??= [];
		$this->limitBucket[(string)$event->sender] []= $now;

		if ($this->isOverLimit((string)$event->sender)) {
			$this->executeOverrateAction($event);
		}
	}

	/**
	 * Check if $sender has executed more commands per time frame than allowed
	 */
	public function isOverLimit(string $sender): bool {
		$exemptRank = $this->limitsExemptRank;
		$sendersRank = $this->accessManager->getAccessLevelForCharacter($sender);
		if ($this->accessManager->compareAccessLevels($sendersRank, $exemptRank) >= 0) {
			return false;
		}
		if ($this->rateIgnoreController->check($sender)) {
			return false;
		}
		$timeWindow = $this->limitsWindow;
		$now = time();
		// Remove all entries older than $timeWindow from the queue
		$this->limitBucket[$sender] = array_values(
			array_filter(
				$this->limitBucket[$sender] ?? [],
				function(int $ts) use ($now, $timeWindow): bool {
					return $ts >= $now - $timeWindow;
				}
			)
		);
		$numExecuted = count($this->limitBucket[$sender]);
		$threshold = $this->limitsThreshold;

		return $threshold && $numExecuted > $threshold;
	}

	/**
	 * Check if a command handler does count against our limits or not.
	 * Aliases for example do not count, because else they would count twice.
	 */
	public function commandHandlerCounts(CommandHandler $ch): bool {
		if ($ch->files === ["CommandAlias.process"]) {
			return false;
		}
		return true;
	}

	/**
	 * Trigger the configured action, because $event was over the allowed threshold
	 */
	public function executeOverrateAction(CmdEvent $event): void {
		$action = $this->limitsOverrateAction;
		$blockadeLength = $this->limitsIgnoreDuration;
		if ($action & 1) {
			if (isset($this->chatBot->chatlist[$event->sender])) {
				$this->chatBot->sendPrivate("Slow it down with the commands, <highlight>{$event->sender}<end>.");
				$this->logger->notice("Kicking {$event->sender} from private channel.");
				$this->chatBot->privategroup_kick($event->sender);
				$audit = new Audit();
				$audit->actor = (string)$event->sender;
				$audit->action = AccessManager::KICK;
				$audit->value = "limits exceeded";
				$this->accessManager->addAudit($audit);
			}
		}
		if ($action & 2) {
			$uid = $this->chatBot->get_uid($event->sender);
			if (is_int($uid)) {
				$this->logger->notice("Blocking {$event->sender} for {$blockadeLength}s.");
				$this->banController->add($uid, (string)$event->sender, $blockadeLength, "Too many commands executed");
			}
		}
		if ($action & 4) {
			$this->logger->notice("Ignoring {$event->sender} for {$blockadeLength}s.");
			$this->ignore((string)$event->sender, $blockadeLength);
		}
	}

	/**
	 * Temporarily ignore $sender for $duration seconds
	 * No command will even be tried to execute, no notification - nothing
	 */
	public function ignore(string $sender, int $duration): bool {
		$this->ignoreList[$sender] = time() + $duration;
		$this->logger->notice("Ignoring {$sender} for {$duration}s.");
		return true;
	}

	/**
	 * Check if $sender is on the ignore list and still ignored
	 */
	public function isIgnored(string $sender): bool {
		$ignoredUntil = $this->ignoreList[$sender] ?? null;
		return $ignoredUntil !== null && $ignoredUntil >= time();
	}

	#[NCA\Event(
		name: "timer(1min)",
		description: "Check ignores to see if they have expired",
		defaultStatus: 1
	)]
	public function expireIgnores(): void {
		$now = time();
		foreach ($this->ignoreList as $name => $expires) {
			if ($expires < $now) {
				unset($this->ignoreList[$name]);
				$this->logger->notice("Unignoring {$name} again.");
			}
		}
	}

	#[NCA\Event(
		name: "timer(10min)",
		description: "Cleanup expired command counts",
		defaultStatus: 1
	)]
	public function expireBuckets(): void {
		$now = time();
		$timeWindow = $this->limitsWindow;
		foreach ($this->limitBucket as $user => &$bucket) {
			$bucket = array_filter(
				$bucket,
				function(int $ts) use ($now, $timeWindow): bool {
					return $ts >= $now - $timeWindow;
				}
			);
			if (empty($bucket)) {
				unset($this->limitBucket[$user]);
			}
		}
	}
}
