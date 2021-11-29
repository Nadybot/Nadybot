<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	DB,
	EventManager,
	LoggerWrapper,
	MessageHub,
	MessageEmitter,
	Modules\DISCORD\DiscordController,
	Nadybot,
	Registry,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	SettingObject,
	SQLException,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PDuration;
use Nadybot\Core\ParamClass\PRemove;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'rtimer',
 *		accessLevel = 'guild',
 *		description = 'Adds a repeating timer',
 *		help        = 'timers.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'timers',
 *		accessLevel = 'guild',
 *		description = 'Sets and shows timers',
 *		help        = 'timers.txt',
 *		alias       = 'timer'
 *	)
 * @ProvidesEvent("timer(start)")
 * @ProvidesEvent("timer(end)")
 * @ProvidesEvent("timer(del)")
 * @ProvidesEvent(value="sync(timer)", desc="Triggered when a new timer is created with the timer command")
 */
class TimerController implements MessageEmitter {

	public const DB_TABLE = "timers_<myname>";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public SettingObject $setting;

	/** @Inject */
	public EventManager $eventManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var Timer[] */
	private $timers = [];

	public function getChannelName(): string {
		return Source::SYSTEM . "(timers)";
	}

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");

		$this->timers = [];
		/** @var Collection<Timer> */
		$data = $this->readAllTimers();
		$data->each(function (Timer $timer) {
			// remove alerts that have already passed
			// leave 1 alert so that owner can be notified of timer finishing
			while (count($timer->alerts) > 1 && $timer->alerts[0]->time <= time()) {
				array_shift($timer->alerts);
			}

			$this->timers[strtolower($timer->name)] = $timer;
		});

		$this->settingManager->add(
			$this->moduleName,
			'timer_alert_times',
			'Times to display timer alerts',
			'edit',
			'text',
			'1h 15m 1m',
			'1h 15m 1m',
			'',
			'mod',
			'timer_alert_times.txt'
		);
		$this->settingManager->registerChangeListener(
			'timer_alert_times',
			[$this, 'changeTimerAlertTimes']
		);
		$this->messageHub->registerMessageEmitter($this);
	}

	/** @return Collection<Timer> */
	public function readAllTimers(): Collection {
		/** @var Collection<Timer> */
		$data = $this->db->table(static::DB_TABLE)
			->select("id", "name", "owner", "mode", "endtime", "settime", "origin")
			->addSelect("callback", "data", "alerts AS alerts_raw")
			->asObj(Timer::class);
		foreach ($data as $row) {
			$alertsData = json_decode($row->alerts_raw);
			foreach ($alertsData as $alertData) {
				$alert = new Alert();
				foreach ($alertData as $key => $value) {
					$alert->{$key} = $value;
				}
				$row->alerts []= $alert;
			}
		}
		return $data;
	}

	public function changeTimerAlertTimes(string $settingName, string $oldValue, $newValue, $data): void {
		$alertTimes = array_reverse(explode(' ', $newValue));
		$oldTime = 0;
		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			if ($time === 0) {
				// invalid time
				throw new Exception("Error saving setting: invalid alert time('$alertTime'). For more info type !help timer_alert_times.");
			} elseif ($time <= $oldTime) {
				// invalid alert order
				throw new Exception("Error saving setting: invalid alert order('$alertTime'). For more info type !help timer_alert_times.");
			}
			$oldTime = $time;
		}
	}

	/**
	 * @Event("timer(1sec)")
	 * @Description("Checks timers and periodically updates chat with time left")
	 */
	public function checkTimers(): void {
		$time = time();

		foreach ($this->timers as $timer) {
			if (count($timer->alerts) === 0) {
				$this->remove($timer->name);
				continue;
			}

			foreach ($timer->alerts as $alert) {
				if ($alert->time > $time) {
					break;
				}

				array_shift($timer->alerts);

				[$name, $method] = explode(".", $timer->callback);
				$instance = Registry::getInstance($name);
				if ($instance === null) {
					$this->logger->log('ERROR', "Error calling callback method '$timer->callback' for timer '$timer->name': Could not find instance '$name'.");
					continue;
				}
				try {
					$instance->{$method}($timer, $alert);
				} catch (Exception $e) {
					$this->logger->log("ERROR", "Error calling callback method '$timer->callback' for timer '$timer->name': " . $e->getMessage(), $e);
				}
				if (empty($timer->alerts)) {
					$event = new TimerEvent();
					$event->timer = $timer;
					$event->type = "timer(end)";
					$this->eventManager->fireEvent($event);
				}
			}
		}
	}

	public function timerCallback(Timer $timer, Alert $alert): void {
		$this->sendAlertMessage($timer, $alert);
	}

	public function repeatingTimerCallback(Timer $timer, Alert $alert): void {
		$this->sendAlertMessage($timer, $alert);

		if (count($timer->alerts) !== 0) {
			return;
		}
		$endTime = (int)$timer->data + $alert->time;
		$alerts = $this->generateAlerts($timer->owner, $timer->name, $endTime, explode(' ', $this->setting->timer_alert_times));
		$this->remove($timer->id);
		$this->add($timer->name, $timer->owner, $timer->mode, $alerts, $timer->callback, $timer->data, $timer->origin);
	}

	public function sendAlertMessage(Timer $timer, Alert $alert): void {
		$msg = $alert->message;
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, "timers"));
		if (!isset($timer->mode) || $timer->mode === "") {
			$delivered = false;
			if ($this->messageHub->handle($rMsg) === MessageHub::EVENT_DELIVERED) {
				$delivered = true;
			}
			if (isset($timer->origin) && !$this->messageHub->hasRouteFromTo($this->getChannelName(), $timer->origin)) {
				$receiver = $this->messageHub->getReceiver($timer->origin);
				if (isset($receiver)) {
					$receiver->receive($rMsg, preg_replace("/^.*\((.+)\)$/", "$1", $timer->origin));
					$delivered = true;
				}
			}
			if ($delivered) {
				return;
			}
		}
		$mode = (isset($timer->mode) && strlen($timer->mode)) ? explode(",", $timer->mode) : [];
		$sent = false;
		foreach ($mode as $sendMode) {
			if ($sendMode === "priv") {
				$this->chatBot->sendPrivate($msg, true);
				$sent = true;
			} elseif (in_array($sendMode, ["org", "guild"], true)) {
				$this->chatBot->sendGuild($msg, true);
				$sent = true;
			} elseif ($sendMode === "discord") {
				$this->discordController->sendDiscord($msg);
				$sent = true;
			}
		}
		if ($sent) {
			return;
		}
		if (isset($timer->origin) && preg_match("/^(discordmsg|console)/", $timer->origin)) {
			$receiver = $this->messageHub->getReceiver($timer->origin);
			if (isset($receiver) && $receiver->receive($rMsg, preg_replace("/^.*\((.+)\)$/", "$1", $timer->origin))) {
				return;
			}
		}
		$this->chatBot->sendMassTell($msg, $timer->owner);
	}

	/**
	 * This command handler adds a repeating timer.
	 *
	 * @HandlesCommand("rtimer")
	 * @Mask $action add
	 */
	public function rtimerCommand(
		CmdContext $context,
		?string $action,
		PDuration $initial,
		PDuration $interval,
		string $name
	): void {
		$alertChannel = $this->getTimerAlertChannel($context->channel);

		$timer = $this->get($name);
		if ($timer !== null) {
			$msg = "A timer with the name <highlight>{$name}<end> is already running.";
			$context->reply($msg);
			return;
		}

		$initialRunTime = $initial->toSecs();
		$runTime = $interval->toSecs();

		if ($runTime < 1) {
			$msg = "You must enter a valid time parameter for the run time.";
			$context->reply($msg);
			return;
		}

		if ($initialRunTime < 1) {
			$msg = "You must enter a valid time parameter for the initial run time.";
			$context->reply($msg);
			return;
		}

		$endTime = time() + $initialRunTime;

		$alerts = $this->generateAlerts($context->char->name, $name, $endTime, explode(' ', $this->setting->timer_alert_times));

		$sendto = $context->sendto;
		$origin = ($sendto instanceof MessageEmitter) ? $sendto->getChannelName() : null;
		$this->add($name, $context->char->name, $alertChannel, $alerts, "timercontroller.repeatingTimerCallback", (string)$runTime, $origin);

		$initialTimerSet = $this->util->unixtimeToReadable($initialRunTime);
		$timerSet = $this->util->unixtimeToReadable($runTime);
		$msg = "Repeating timer <highlight>$name<end> will go off in $initialTimerSet and repeat every $timerSet.";

		$context->reply($msg);

		$sTimer = new SyncTimerEvent();
		$sTimer->name = $name;
		$sTimer->endtime = $endTime;
		$sTimer->settime = time();
		$sTimer->interval = $runTime;
		$sTimer->owner = $context->char->name;
		$sTimer->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($sTimer);
	}

	/**
	 * @HandlesCommand("timers")
	 * @Mask $action view
	 */
	public function timersViewCommand(CmdContext $context, string $action, string $id): void {
		$timer = $this->get($id);
		if ($timer === null) {
			if (preg_match("/^\d+$/", $id)) {
				$msg = "Could not find timer <highlight>#{$id}<end>.";
			} else {
				$msg = "Could not find a timer named <highlight>{$id}<end>.";
			}
			$context->reply($msg);
			return;
		}
		$timeLeft = "an unknown amount of time";
		if (isset($timer->endtime)) {
			$timeLeft = $this->util->unixtimeToReadable($timer->endtime - time());
		}
		$name = $timer->name;

		$msg = "Timer <highlight>{$name}<end> has <highlight>{$timeLeft}<end> left.";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("timers")
	 */
	public function timersRemoveCommand(CmdContext $context, PRemove $action, int $id): void {
		$timer = $this->get($id);
		if ($timer === null) {
			$msg = "Could not find timer <highlight>#{$id}<end>.";
		} elseif ($timer->owner !== $context->char->name && !$this->accessManager->checkAccess($context->char->name, "mod")) {
			$msg = "You must own this timer or have moderator access in order to remove it.";
		} else {
			$event = new TimerEvent();
			$event->timer = $timer;
			$event->type = "timer(del)";
			$this->eventManager->fireEvent($event);
			$this->remove($id);
			$msg = "Removed timer <highlight>$timer->name<end>.";
		}
		$context->reply($msg);
	}

	protected function getTimerAlertChannel(string ...$channels): string {
		// Timers via tell always create tell alerts only
		if ($channels === ["msg"]) {
			return "msg";
		}
		return "";
	}

	/**
	 * @HandlesCommand("timers")
	 * @Mask $action add
	 */
	public function timersAddCommand(
		CmdContext $context,
		?string $action,
		PDuration $duration,
		?string $name
	): void {
		$name ??= $context->char->name;

		$runTime = $duration->toSecs();
		$alertChannel = $this->getTimerAlertChannel($context->channel);

		$sendto = $context->sendto;
		$origin = ($sendto instanceof MessageEmitter) ? $sendto->getChannelName() : null;
		$msg = $this->addTimer($context->char->name, $name, $runTime, $alertChannel, null, $origin);
		$sendto->reply($msg);
		if (preg_match("/has been set for/", $msg)) {
			$sTimer = new SyncTimerEvent();
			$sTimer->name = $name;
			$sTimer->endtime = time() + $runTime;
			$sTimer->settime = time();
			$sTimer->interval;
			$sTimer->owner = $context->char->name;
			$sTimer->forceSync = $context->forceSync;
			$this->eventManager->fireEvent($sTimer);
		}
	}

	/**
	 * @HandlesCommand("timers")
	 */
	public function timersListCommand(CmdContext $context): void {
		$timers = $this->getAllTimers();
		$count = count($timers);
		if ($count === 0) {
			$msg = "No timers currently running.";
			$context->reply($msg);
			return;
		}
		$blob = '';
		// Sort timers by time until going off
		usort($timers, function(Timer $a, Timer $b) {
			return $a->endtime <=> $b->endtime;
		});
		foreach ($timers as $timer) {
			$timeLeft = "&lt;unknown&gt;";
			if (isset($timer->endtime)) {
				$timeLeft = $this->util->unixtimeToReadable($timer->endtime - time());
			}
			$name = $timer->name;
			$owner = $timer->owner;

			$remove_link = $this->text->makeChatcmd("Remove", "/tell <myname> timers rem {$timer->id}");

			$repeatingInfo = '';
			if ($timer->callback === 'timercontroller.repeatingTimerCallback') {
				$repeatingTimeString = $this->util->unixtimeToReadable((int)$timer->data);
				$repeatingInfo = " (Repeats every $repeatingTimeString)";
			}

			$blob .= "Name: <highlight>$name<end> {$remove_link}\n";
			$blob .= "Time left: <highlight>$timeLeft<end> $repeatingInfo\n";
			$blob .= "Set by: <highlight>$owner<end>\n\n";
		}
		$msg = $this->text->makeBlob("Timers ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * Generate alerts out of an alert specification
	 *
	 * @param string $sender Name of the player
	 * @param string $name Name of the alert
	 * @param int $endTime When to trigger the timer
	 * @param string[] $alertTimes A list og alert times (human readable)
	 * @return Alert[]
	 */
	public function generateAlerts(string $sender, string $name, int $endTime, array $alertTimes): array {
		$alerts = [];

		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			$timeString = $this->util->unixtimeToReadable($time);
			if ($endTime - $time > time()) {
				$alert = new Alert();
				$alert->message = "Reminder: Timer <highlight>$name<end> has <highlight>$timeString<end> left. [set by <highlight>$sender<end>]";
				$alert->time = $endTime - $time;
				$alerts []= $alert;
			}
		}

		if ($endTime > time()) {
			$alert = new Alert;
			if ($name === $sender) {
				$alert->message = "<highlight>$sender<end>, your timer has gone off.";
			} else {
				$alert->message = "<highlight>$sender<end>, your timer named <highlight>$name<end> has gone off.";
			}
			$alert->time = $endTime;
			$alerts []= $alert;
		}

		return $alerts;
	}

	/**
	 * Add a timer
	 *
	 * @param string $sender Name of the creator
	 * @param string $name Name of the timer
	 * @param int $runTime When to trigger
	 * @param string $channel Where to show (comma-separated)
	 * @param Alert[]|null $alerts List of alert when to display things
	 * @return string Message to display
	 * @throws SQLException
	 */
	public function addTimer(string $sender, string $name, int $runTime, ?string $channel=null, ?array $alerts=null, ?string $origin=null): string {
		if ($name === '') {
			return '';
		}

		if ($this->get($name) !== null) {
			return "A timer named <highlight>$name<end> is already running.";
		}

		if ($runTime < 1) {
			return "You must enter a valid time parameter.";
		}

		if (strlen($name) > 255) {
			return "You cannot use timer names longer than 255 characters.";
		}

		$endTime = time() + $runTime;

		if ($alerts === null) {
			$alerts = $this->generateAlerts($sender, $name, $endTime, explode(' ', $this->setting->timer_alert_times));
		}

		$this->add($name, $sender, $channel, $alerts, 'timercontroller.timerCallback', null, $origin);

		$timerset = $this->util->unixtimeToReadable($runTime);
		return "Timer <highlight>$name<end> has been set for <highlight>$timerset<end>.";
	}

	/**
	 * @param Alert[] $alerts
	 */
	public function add(string $name, string $owner, ?string $mode, array $alerts, string $callback, string $data=null, ?string $origin=null): int {
		usort($alerts, function(Alert $a, Alert $b) {
			return $a->time <=> $b->time;
		});

		$timer = new Timer();
		$timer->name = $name;
		$timer->owner = $owner;
		$timer->endtime = end($alerts)->time;
		$timer->settime = time();
		$timer->callback = $callback;
		$timer->data = $data;
		$timer->origin = $origin;
		$timer->mode = strlen($mode??"") ? $mode : null;
		$timer->alerts = $alerts;

		$event = new TimerEvent();
		$event->timer = $timer;
		$event->type = "timer(start)";

		$timer->id = $this->db->table(static::DB_TABLE)
			->insertGetId([
				"name" => $name,
				"owner" => $owner,
				"mode" => $timer->mode,
				"origin" => $timer->origin,
				"endtime" => $timer->endtime,
				"settime" => $timer->settime,
				"callback" => $callback,
				"data" => $data,
				"alerts" => json_encode($alerts),
			]);

		$this->timers[strtolower($name)] = $timer;
		$this->eventManager->fireEvent($event);
		return $timer->id;
	}

	public function remove($name): void {
		if (is_string($name)) {
			$this->db->table(static::DB_TABLE)
				->whereIlike("name", $name)
				->delete();
			unset($this->timers[strtolower($name)]);
		} elseif (is_int($name)) {
			$this->db->table(static::DB_TABLE)->delete($name);
			foreach ($this->timers as $tName => $timer) {
				if ($timer->id === $name) {
					unset($this->timers[$tName]);
					return;
				}
			}
		}
	}

	public function get($name): ?Timer {
		$timer = $this->timers[strtolower((string)$name)] ?? null;
		if (isset($timer)) {
			return $timer;
		}
		if (!preg_match("/^\d+$/", (string)$name)) {
			return null;
		}
		foreach ($this->timers as $tName => $timer) {
			if ($timer->id === (int)$name) {
				return $timer;
			}
		}
		return null;
	}

	/**
	 * @return Timer[]
	 */
	public function getAllTimers(): array {
		return $this->timers;
	}

	/**
	 * @Event("sync(timer)")
	 * @Description("Sync external timers to local timers")
	 */
	public function syncExtTimers(SyncTimerEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$timerName = $event->name;
		$i = 1;
		while ($this->get($timerName) !== null) {
			$timerName = $event->name . "-" . (++$i);
		}
		$event->name = $timerName;

		$alerts = $this->generateAlerts($event->owner, $event->name, $event->endtime, explode(' ', $this->setting->timer_alert_times));
		if (isset($event->interval)) {
			$this->add($event->name, $event->owner, null, $alerts, "timercontroller.repeatingTimerCallback", (string)$event->interval);
		} else {
			$this->add($event->name, $event->owner, null, $alerts, 'timercontroller.timerCallback');
		}
	}
}
