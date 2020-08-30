<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Exception;
use Nadybot\Core\{
	AccessManager,
	CommandReply,
	DB,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	SettingObject,
	SQLException,
	Text,
	Util,
	Modules\DISCORD\DiscordController,
};

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
 */
class TimerController {

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
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public DiscordController $discordController;
	
	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public SettingObject $setting;
	
	/** @Logger */
	public LoggerWrapper $logger;

	/** @var Timer[] */
	private $timers = [];

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'timers');

		$this->timers = [];
		/** @var Timer[] */
		$data = $this->db->fetchAll(
			Timer::class,
			"SELECT name, owner, mode, endtime, settime, callback, data, alerts AS alerts_raw FROM timers_<myname>"
		);
		foreach ($data as $row) {
			$alertsData = json_decode($row->alerts_raw);
			foreach ($alertsData as $alertData) {
				$alert = new Alert();
				foreach ($alertData as $key => $value) {
					$alert->{$key} = $value;
				}
				$row->alerts []= $alert;
			}

			// remove alerts that have already passed
			// leave 1 alert so that owner can be notified of timer finishing
			while (count($row->alerts) > 1 && $row->alerts[0]->time <= time()) {
				array_shift($row->alerts);
			}

			$this->timers[strtolower($row->name)] = $row;
		}

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
		$this->add($timer->name, $timer->owner, $timer->mode, $alerts, $timer->callback, $timer->data);
	}

	public function sendAlertMessage(Timer $timer, Alert $alert): void {
		$msg = $alert->message;
		$mode = explode(",", $timer->mode);
		$sent = false;
		foreach ($mode as $sendMode) {
			if ($sendMode === "priv") {
				$this->chatBot->sendPrivate($msg);
				$sent = true;
			} elseif (in_array($sendMode, ["org", "guild"], true)) {
				$this->chatBot->sendGuild($msg);
				$sent = true;
			} elseif ($sendMode === "discord") {
				$this->discordController->sendDiscord($msg);
				$sent = true;
			}
		}
		if ($sent === false) {
			$this->chatBot->sendTell($msg, $timer->owner);
		}
	}

	/**
	 * This command handler adds a repeating timer.
	 *
	 * @HandlesCommand("rtimer")
	 * @Matches("/^(rtimer add|rtimer) ([a-z0-9]+) ([a-z0-9]+) (.+)$/i")
	 */
	public function rtimerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$initialTimeString = $args[2];
		$timeString = $args[3];
		$timerName = $args[4];

		$timer = $this->get($timerName);
		if ($timer !== null) {
			$msg = "A timer with the name <highlight>$timerName<end> is already running.";
			$sendto->reply($msg);
			return;
		}

		$initialRunTime = $this->util->parseTime($initialTimeString);
		$runTime = $this->util->parseTime($timeString);

		if ($runTime < 1) {
			$msg = "You must enter a valid time parameter for the run time.";
			$sendto->reply($msg);
			return;
		}

		if ($initialRunTime < 1) {
			$msg = "You must enter a valid time parameter for the initial run time.";
			$sendto->reply($msg);
			return;
		}

		$endTime = time() + $initialRunTime;
		
		$alerts = $this->generateAlerts($sender, $timerName, $endTime, explode(' ', $this->setting->timer_alert_times));

		$this->add($timerName, $sender, $channel, $alerts, "timercontroller.repeatingTimerCallback", (string)$runTime);

		$initialTimerSet = $this->util->unixtimeToReadable($initialRunTime);
		$timerSet = $this->util->unixtimeToReadable($runTime);
		$msg = "Repeating timer <highlight>$timerName<end> will go off in $initialTimerSet and repeat every $timerSet.";

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("timers")
	 * @Matches("/^timers view (.+)$/i")
	 */
	public function timersViewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = strtolower($args[1]);
		$timer = $this->get($name);
		if ($timer === null) {
			$msg = "Could not find timer named <highlight>$name<end>.";
			$sendto->reply($msg);
			return;
		}
		$time_left = $this->util->unixtimeToReadable($timer->endtime - time());
		$name = $timer->name;

		$msg = "Timer <highlight>$name<end> has <highlight>$time_left<end> left.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("timers")
	 * @Matches("/^timers (rem|del) (.+)$/i")
	 */
	public function timersRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = strtolower($args[2]);
		$timer = $this->get($name);
		if ($timer === null) {
			$msg = "Could not find a timer named <highlight>$name<end>.";
		} elseif ($timer->owner !== $sender && !$this->accessManager->checkAccess($sender, "mod")) {
			$msg = "You must own this timer or have moderator access in order to remove it.";
		} else {
			$this->remove($name);
			$msg = "Removed timer <highlight>$timer->name<end>.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("timers")
	 * @Matches("/^(timers add|timers) ([a-z0-9]+)$/i")
	 * @Matches("/^(timers add|timers) ([a-z0-9]+) (.+)$/i")
	 */
	public function timersAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$timeString = $args[2];
		$name = $sender;
		if (count($args) > 3) {
			$name = $args[3];
		}
		
		if (preg_match("/^\\d+$/", $timeString)) {
			$runTime = $args[2] * 60;
		} else {
			$runTime = $this->util->parseTime($timeString);
		}

		$msg = $this->addTimer($sender, $name, $runTime, $channel);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("timers")
	 * @Matches("/^timers$/i")
	 */
	public function timersListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$timers = $this->getAllTimers();
		$count = count($timers);
		if ($count === 0) {
			$msg = "No timers currently running.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		// Sort timers by time until going off
		usort($timers, function(Timer $a, Timer $b) {
			return $a->endtime <=> $b->endtime;
		});
		foreach ($timers as $timer) {
			$time_left = $this->util->unixtimeToReadable($timer->endtime - time());
			$name = $timer->name;
			$owner = $timer->owner;

			$remove_link = $this->text->makeChatcmd("Remove", "/tell <myname> timers rem $name");

			$repeatingInfo = '';
			if ($timer->callback === 'timercontroller.repeatingTimerCallback') {
				$repeatingTimeString = $this->util->unixtimeToReadable((int)$timer->data);
				$repeatingInfo = " (Repeats every $repeatingTimeString)";
			}

			$blob .= "Name: <highlight>$name<end> {$remove_link}\n";
			$blob .= "Time left: <highlight>$time_left<end> $repeatingInfo\n";
			$blob .= "Set by: <highlight>$owner<end>\n\n";
		}
		$msg = $this->text->makeBlob("Timers ($count)", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * Generate alerts out of an alert specification
	 *
	 * @param string $sender Name of the player
	 * @param string $name Name ofthe alert
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
	public function addTimer(string $sender, string $name, int $runTime, string $channel, ?array $alerts=null): string {
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

		$this->add($name, $sender, $channel, $alerts, 'timercontroller.timerCallback');

		$timerset = $this->util->unixtimeToReadable($runTime);
		return "Timer <highlight>$name<end> has been set for <highlight>$timerset<end>.";
	}

	/**
	 * @param Alert[] $alerts
	 */
	public function add(string $name, string $owner, string $mode, array $alerts, string $callback, string $data=null): void {
		usort($alerts, function(Alert $a, Alert $b) {
			return $a->time <=> $b->time;
		});

		$timer = new Timer();
		$timer->name = $name;
		$timer->owner = $owner;
		$timer->mode = $mode;
		$timer->endtime = end($alerts)->time;
		$timer->settime = time();
		$timer->callback = $callback;
		$timer->data = $data;
		$timer->alerts = $alerts;

		$this->timers[strtolower($name)] = $timer;

		$sql = "INSERT INTO timers_<myname> (`name`, `owner`, `mode`, `endtime`, `settime`, `callback`, `data`, alerts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
		$this->db->exec($sql, $name, $owner, $mode, $timer->endtime, $timer->settime, $callback, $data, json_encode($alerts));
	}

	public function remove(string $name): void {
		$this->db->exec("DELETE FROM timers_<myname> WHERE `name` LIKE ?", $name);
		unset($this->timers[strtolower($name)]);
	}

	public function get($name): ?Timer {
		return $this->timers[strtolower($name)] ?? null;
	}

	/**
	 * @return Timer[]
	 */
	public function getAllTimers(): array {
		return $this->timers;
	}
}
