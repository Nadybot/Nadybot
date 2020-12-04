<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS;

use Nadybot\Core\{
	AccessManager,
	CmdEvent,
	CommandHandler,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Timer,
	Util,
};
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\Modules\CONFIG\ConfigController;
use Nadybot\Core\Modules\PLAYER_LOOKUP\{
	PlayerHistory,
    PlayerHistoryData,
    PlayerHistoryManager,
	PlayerManager,
};
use PhpAmqpLib\Exception\AMQPConnectionClosedException;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class LimitsController {
	public const ALL = 3;
	public const FAILURE = 2;
	public const SUCCESS = 1;
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public PlayerManager $playerManager;
	
	/** @Inject */
	public PlayerHistoryManager $playerHistoryManager;
	
	/** @Inject */
	public Util $util;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public RateIgnoreController $rateIgnoreController;

	/** @Inject */
	public ConfigController $configController;

	/** @Inject */
	public BanController $banController;
	
	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,int[]> */
	public array $limitBucket = [];

	/** @var array<string,int> */
	public array $ignoreList = [];
	
	/**
	 * @Setup
	 */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			"tell_req_lvl",
			"Minimum level required to send tell to bot",
			"edit",
			"number",
			"0",
			"0;10;50;100;150;190;205;215"
		);
		$this->settingManager->add(
			$this->moduleName,
			"tell_req_faction",
			"Faction required to send tell to bot",
			"edit",
			"options",
			"all",
			"all;Omni;Neutral;Clan;not Omni;not Neutral;not Clan"
		);
		$this->settingManager->add(
			$this->moduleName,
			"tell_min_player_age",
			"Minimum age of player to send tell to bot",
			"edit",
			"time",
			"1s",
			"1s;7days;14days;1month;2months;6months;1year;2years",
			'',
			'mod',
			'limits.txt'
		);
		$this->settingManager->add(
			$this->moduleName,
			"tell_error_msg_type",
			"How to show error messages when limit requirements are not met?",
			"edit",
			"options",
			"2",
			"Specific;Generic;None",
			"2;1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"limits_cmd_type",
			"Ratelimit: Which commands to account for?",
			"edit",
			"options",
			"0",
			"All;Only errors/denied;Only successes;None",
			"3;2;1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"limits_window",
			"Ratelimit: Which time window to check?",
			"edit",
			"options",
			"5",
			"5s;10s;30s;1m",
			"5;10;30;60"
		);
		$this->settingManager->add(
			$this->moduleName,
			"limits_threshold",
			"Ratelimit: How many commands per time window trigger actions?",
			"edit",
			"number",
			"5",
			"off;2;3;4;5;6;7;8;9;10",
			"0;2;3;4;5;6;7;8;9;10"
		);
		$this->settingManager->add(
			$this->moduleName,
			"limits_overrate_action",
			"Ratelimit: Action when players exceed the allowed command rate",
			"edit",
			"options",
			"4",
			"Kick;Temp. ban;Kick+Temp. ban;Temp. ignore;Kick+Temp. ignore",
			"1;2;3;4;5"
		);
		$this->settingManager->add(
			$this->moduleName,
			"limits_ignore_duration",
			"Ratelimit: How long to temporarily ban or ignore?",
			"edit",
			"time",
			"5m",
			"1m;2m;5m;10m;30m;1h;6h",
		);
		$this->settingManager->add(
			$this->moduleName,
			"limits_exempt_rank",
			"Ratelimit: Ignore ratelimit for everyone of this rank or higher",
			"edit",
			"rank",
			"mod"
		);
	}
	
	/**
	 * Check if $sender is allowed to send $message
	 */
	public function checkAndExecute(string $sender, string $message, callable $callback, ...$args): void {
		if (
			preg_match("/^about$/i", $message)
			|| $this->rateIgnoreController->check($sender)
			|| $sender === ucfirst(strtolower($this->settingManager->get("relaybot")))
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
		$this->logger->log('Info', "$sender denied access to bot due to: $msg");

		$this->handleLimitCheckFail($msg, $sender);

		$cmd = explode(' ', $message, 2)[0];
		$cmd = strtolower($cmd);

		if ($this->settingManager->getBool('access_denied_notify_guild')) {
			$this->chatBot->sendGuild("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end> due to limit checks.", true);
		}
		if ($this->settingManager->getBool('access_denied_notify_priv')) {
			$this->chatBot->sendPrivate("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end> due to limit checks.", true);
		}
	}
	
	/**
	 * React to a $sender being denied to send $msg to us
	 */
	public function handleLimitCheckFail(string $msg, string $sender): void {
		if ($this->settingManager->getInt('tell_error_msg_type') === 2) {
			$this->chatBot->sendTell($msg, $sender);
		} elseif ($this->settingManager->getInt('tell_error_msg_type') === 1) {
			$msg = "Error! You do not have access to this bot.";
			$this->chatBot->sendTell($msg, $sender);
		}
	}
	
	protected function handleLevelAndFactionRequirements(?Player $whois, callable $errorHandler, callable $successHandler, ...$args): void {
		if ($whois === null) {
			$errorHandler("Error! Unable to get your character info for limit checks. Please try again later.");
			return;
		}
		$tellReqFaction = $this->settingManager->get('tell_req_faction');
		$tellReqLevel = $this->settingManager->getInt('tell_req_lvl');

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
	 */
	public function checkAccessError(string $sender, callable $errorHandler, callable $successHandler, ...$args): void {
		$minAgeCheck = function() use ($sender, $errorHandler, $successHandler, $args): void {
			if ($this->settingManager->getInt("tell_min_player_age") <= 1) {
				$successHandler(...$args);
				return;
			}
			$this->playerHistoryManager->asyncLookup(
				$sender,
				(int)$this->chatBot->vars['dimension'],
				function(?PlayerHistory $history, callable $errorHandler, callable $successHandler, ...$args): void {
					$this->handleMinAgeRequirements($history, $errorHandler, $successHandler, ...$args);
				},
				$errorHandler,
				$successHandler,
				...$args
			);
		};
		$tellReqFaction = $this->settingManager->get('tell_req_faction');
		$tellReqLevel = $this->settingManager->getInt('tell_req_lvl');
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

	protected function handleMinAgeRequirements(?PlayerHistory $history, callable $errorHandler, callable $successHandler, ...$args): void {
		if ($history === null) {
			$errorHandler("Error! Unable to get your character history for limit checks. Please try again later.");
			return;
		}
		$minAge = time() - $this->settingManager->getInt("tell_min_player_age");
		/** @var PlayerHistoryData */
		$entry = array_pop($history->data);
		// TODO check for rename

		if ($entry->last_changed->getTimestamp() > $minAge) {
			$timeString = $this->util->unixtimeToReadable($this->settingManager->getInt("tell_min_player_age"));
			$errorHandler("Error! You must be at least <highlight>$timeString<end> old.");
			return;
		}
		
		$successHandler(...$args);
	}
		
	/**
	 * @Event("command(*)")
	 * @Description("Enforce rate limits")
	 */
	public function accountCommandExecution(CmdEvent $event): void {
		if ($event->cmdHandler && !$this->commandHandlerCounts($event->cmdHandler)) {
			return;
		}
		$toCount = $this->settingManager->getInt('limits_cmd_type');
		$isSuccess = in_array($event->type, ["command(success)"]);
		$isFailure = !in_array($event->type, ["command(success)"]);
		if (($isSuccess && ($toCount & static::SUCCESS) === 0)
			|| ($isFailure && ($toCount & static::FAILURE) === 0)) {
			return;
		}
		$now = time();
		$this->limitBucket[$event->sender] ??= [];
		$this->limitBucket[$event->sender] []= $now;

		if ($this->isOverLimit($event->sender)) {
			$this->executeOverrateAction($event);
		}
	}

	/**
	 * Check if $sender has executed more commands per time frame than allowed
	 */
	public function isOverLimit(string $sender): bool {
		$exemptRank = $this->settingManager->getString("limits_exempt_rank") ?? "mod";
		$sendersRank = $this->accessManager->getAccessLevelForCharacter($sender);
		if ($this->accessManager->compareAccessLevels($sendersRank, $exemptRank) >= 0) {
			return false;
		}
		if ($this->rateIgnoreController->check($sender)) {
			return false;
		}
		$timeWindow = $this->settingManager->getInt('limits_window');
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
		$threshold = $this->settingManager->getInt('limits_threshold');
		
		return $threshold && $numExecuted > $threshold;
	}

	/**
	 * Check if a command handler does count against our limits or not.
	 * Aliases for example do not count, because else they would count twice.
	 */
	public function commandHandlerCounts(CommandHandler $ch): bool {
		if ($ch->file === "CommandAlias.process") {
			return false;
		}
		return true;
	}

	/**
	 * Trigger the configured action, because $event was over the allowed threshold
	 */
	public function executeOverrateAction(CmdEvent $event): void {
		$action = $this->settingManager->getInt('limits_overrate_action');
		$blockadeLength =$this->settingManager->getInt('limits_ignore_duration');
		if ($action & 1) {
			if (isset($this->chatBot->chatlist[$event->sender])) {
				$this->chatBot->sendPrivate("Slow it down with the commands, <highlight>{$event->sender}<end>.");
				$this->logger->log('INFO', "Kicking {$event->sender} from private channel.");
				$this->chatBot->privategroup_kick($event->sender);
			}
		}
		if ($action & 2) {
			$uid = $this->chatBot->get_uid($event->sender);
			if ($uid) {
				$this->logger->log('INFO', "Blocking {$event->sender} for {$blockadeLength}s.");
				$this->banController->add($uid, $event->sender, $blockadeLength, "Too many commands executed");
			}
		}
		if ($action & 4) {
			$this->logger->log('INFO', "Ignoring {$event->sender} for {$blockadeLength}s.");
			$this->ignore($event->sender, $blockadeLength);
		}
	}

	/**
	 * Temporarily ignore $sender for $duration seconds
	 * No command will even be tried to execute, no notification - nothing
	 */
	public function ignore(string $sender, int $duration): bool {
		$this->ignoreList[$sender] = time() + $duration;
		$this->logger->log('INFO', "Ignoring {$sender} for {$duration}s.");
		return true;
	}

	/**
	 * Check if $sender is on the ignore list and still ignored
	 */
	public function isIgnored(string $sender): bool {
		$ignoredUntil = $this->ignoreList[$sender] ?? null;
		return $ignoredUntil !== null && $ignoredUntil >= time();
	}

	/**
	 * @Event("timer(1min)")
	 * @Description("Check ignores to see if they have expired")
	 * @DefaultStatus("1")
	 */
	public function expireIgnores(): void {
		$now = time();
		foreach ($this->ignoreList as $name => $expires) {
			if ($expires < $now) {
				unset($this->ignoreList[$name]);
				$this->logger->log('INFO', "Unignoring {$name} again.");
			}
		}
	}

	/**
	 * @Event("timer(10min)")
	 * @Description("Cleanup expired command counts")
	 * @DefaultStatus("1")
	 */
	public function expireBuckets(): void {
		$now = time();
		$timeWindow = $this->settingManager->getInt('limits_window');
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
