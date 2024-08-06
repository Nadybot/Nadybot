<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use function Safe\{preg_match};
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	EventManager,
	Exceptions\SQLException,
	MessageHub,
	ModuleInstance,
	Modules\DISCORD\DiscordController,
	Nadybot,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Registry,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Text,
	Types\MessageEmitter,
	Util,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use ReflectionClass;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'rtimer',
		accessLevel: 'guild',
		description: 'Adds a repeating timer',
	),
	NCA\DefineCommand(
		command: 'timers',
		accessLevel: 'guild',
		description: 'Sets and shows timers',
		alias: 'timer'
	),
	NCA\ProvidesEvent(TimerStartEvent::class),
	NCA\ProvidesEvent(TimerEndEvent::class),
	NCA\ProvidesEvent(TimerDelEvent::class),
	NCA\ProvidesEvent(
		event: 'sync(timer)',
		desc: 'Triggered when a new timer is created with the timer command',
	)
]
class TimerController extends ModuleInstance implements MessageEmitter {
	/** Times to display timer alerts */
	#[NCA\Setting\Text(
		options: ['1h 15m 1m'],
		help: 'timer_alert_times.txt',
	)]
	public string $timerAlertTimes = '1h 15m 1m';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DiscordController $discordController;

	#[NCA\Inject]
	private EventManager $eventManager;

	/** @var array<string,Timer> */
	private $timers = [];

	public function getChannelName(): string {
		return Source::SYSTEM . '(timers)';
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->timers = [];

		$data = $this->readAllTimers();
		$data->each(function (Timer $timer): void {
			// remove alerts that have already passed
			// leave 1 alert so that owner can be notified of timer finishing
			while (count($timer->alerts) > 1 && $timer->alerts[0]->time <= time()) {
				array_shift($timer->alerts);
			}

			$this->timers[strtolower($timer->name)] = $timer;
		});

		$this->messageHub->registerMessageEmitter($this);
	}

	/** @return Collection<int,Timer> */
	public function readAllTimers(): Collection {
		$data = $this->db->table(Timer::getTable())
			->select(['id', 'name', 'owner', 'mode', 'endtime', 'settime', 'origin'])
			->addSelect(['callback', 'data', 'alerts'])
			->asObj(Timer::class);
		return $data;
	}

	#[NCA\SettingChangeHandler('timer_alert_times')]
	public function changeTimerAlertTimes(string $settingName, string $oldValue, string $newValue, mixed $data): void {
		$alertTimes = array_reverse(explode(' ', $newValue));
		$oldTime = 0;
		foreach ($alertTimes as $alertTime) {
			$time = Util::parseTime($alertTime);
			if ($time === 0) {
				// invalid time
				throw new Exception("Error saving setting: invalid alert time('{$alertTime}'). For more info type !help timer_alert_times.");
			} elseif ($time <= $oldTime) {
				// invalid alert order
				throw new Exception("Error saving setting: invalid alert order('{$alertTime}'). For more info type !help timer_alert_times.");
			}
			$oldTime = $time;
		}
	}

	#[NCA\Event(
		name: 'timer(1sec)',
		description: 'Checks timers and periodically updates chat with time left'
	)]
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

				[$name, $method] = explode('.', $timer->callback);
				$instance = Registry::tryGetInstance($name);
				if ($instance === null) {
					$this->logger->error("Error calling callback method '{callback}' for timer '{timer}': Could not find instance '{instance}'.", [
						'callback' => $timer->callback,
						'timer' => $timer->name,
						'instance' => $name,
					]);
					continue;
				}
				try {
					$refClass = new ReflectionClass($instance);
					$refMethod = $refClass->getMethod($method);
					$refMethod->invoke($instance, $timer, $alert);
				} catch (Exception $e) {
					$this->logger->error("Error calling callback method '{callback}' for timer '{timer}': {error}.", [
						'callback' => $timer->callback,
						'timer' => $timer->name,
						'error' => $e->getMessage(),
						'exception' => $e,
					]);
				}
				if (!count($timer->alerts)) {
					$event = new TimerEndEvent(timer: $timer);
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
		$alerts = $this->generateAlerts($timer->owner, $timer->name, $endTime, explode(' ', $this->timerAlertTimes));
		$this->remove($timer->id);
		$this->add(
			name: $timer->name,
			owner: $timer->owner,
			mode: $timer->mode,
			alerts: $alerts,
			callback: $timer->callback,
			data: $timer->data,
			origin: $timer->origin,
			id: $timer->id
		);
	}

	public function sendAlertMessage(Timer $timer, Alert $alert): void {
		$msg = $alert->message;
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, 'timers'));
		if (!isset($timer->mode) || $timer->mode === '') {
			$delivered = false;
			if ($this->messageHub->handle($rMsg) === MessageHub::EVENT_DELIVERED) {
				$delivered = true;
			}
			if (isset($timer->origin) && !$this->messageHub->hasRouteFromTo($this->getChannelName(), $timer->origin)) {
				$receiver = $this->messageHub->getReceiver($timer->origin);
				if (isset($receiver)) {
					$receiver->receive($rMsg, Safe::pregReplace("/^.*\((.+)\)$/", '$1', $timer->origin));
					$delivered = true;
				}
			}
			if ($delivered) {
				return;
			}
		}
		$mode = (isset($timer->mode) && strlen($timer->mode)) ? explode(',', $timer->mode) : [];
		$sent = false;
		foreach ($mode as $sendMode) {
			if ($sendMode === 'priv') {
				$this->chatBot->sendPrivate($msg, true);
				$sent = true;
			} elseif (in_array($sendMode, ['org', 'guild'], true)) {
				$this->chatBot->sendGuild($msg, true);
				$sent = true;
			} elseif ($sendMode === 'discord') {
				$this->discordController->sendDiscord($msg);
				$sent = true;
			}
		}
		if ($sent) {
			return;
		}
		if (isset($timer->origin) && preg_match('/^(discordmsg|console)/', $timer->origin)) {
			$receiver = $this->messageHub->getReceiver($timer->origin);
			if (isset($receiver) && $receiver->receive($rMsg, Safe::pregReplace("/^.*\((.+)\)$/", '$1', $timer->origin))) {
				return;
			}
		}
		$this->chatBot->sendMassTell($msg, $timer->owner);
	}

	/** Create a new repeating timer, repeating every &lt;interval&gt; after &lt;initial&gt; */
	#[NCA\HandlesCommand('rtimer')]
	#[NCA\Help\Group('timers')]
	#[NCA\Help\Example(
		command: '<symbol>rtimer add 1m 15m Drink reminder',
		description: 'Start a timer that will wait for 1 minute and then go off '.
			'every 15 minutes until deleted'
	)]
	public function rtimerCommand(
		CmdContext $context,
		#[NCA\Str('add')] ?string $action,
		PDuration $initial,
		PDuration $interval,
		string $name
	): void {
		$alertChannel = $this->getTimerAlertChannel($context);

		$timer = $this->get($name);
		if ($timer !== null) {
			$msg = "A timer with the name <highlight>{$name}<end> is already running.";
			$context->reply($msg);
			return;
		}

		$initialRunTime = $initial->toSecs();
		$runTime = $interval->toSecs();

		if ($runTime < 1) {
			$msg = 'You must enter a valid time parameter for the run time.';
			$context->reply($msg);
			return;
		}

		if ($initialRunTime < 1) {
			$msg = 'You must enter a valid time parameter for the initial run time.';
			$context->reply($msg);
			return;
		}

		$endTime = time() + $initialRunTime;

		$alerts = $this->generateAlerts($context->char->name, $name, $endTime, explode(' ', $this->timerAlertTimes));

		$sendto = $context->sendto;
		$origin = ($sendto instanceof MessageEmitter) ? $sendto->getChannelName() : null;
		$this->add($name, $context->char->name, $alertChannel, $alerts, 'timercontroller.repeatingTimerCallback', (string)$runTime, $origin);

		$initialTimerSet = Util::unixtimeToReadable($initialRunTime);
		$timerSet = Util::unixtimeToReadable($runTime);
		$msg = "Repeating timer <highlight>{$name}<end> will go off in {$initialTimerSet} and repeat every {$timerSet}.";

		$context->reply($msg);

		$sTimer = new SyncTimerEvent(
			name: $name,
			endtime: $endTime,
			settime: time(),
			interval: $runTime,
			owner: $context->char->name,
			forceSync: $context->forceSync,
		);
		$this->eventManager->fireEvent($sTimer);
	}

	/** Show a specific timer */
	#[NCA\HandlesCommand('timers')]
	#[NCA\Help\Group('timers')]
	public function timersViewCommand(CmdContext $context, #[NCA\Str('view')] string $action, string $id): void {
		$timer = $this->get($id);
		if ($timer === null) {
			if (!Uuid::isValid($id)) {
				$msg = "Could not find timer <highlight>{$id}<end>.";
			} else {
				$msg = "Could not find a timer named <highlight>{$id}<end>.";
			}
			$context->reply($msg);
			return;
		}
		$timeLeft = 'an unknown amount of time';
		if (isset($timer->endtime)) {
			$timeLeft = Util::unixtimeToReadable($timer->endtime - time());
		}
		$name = $timer->name;

		$msg = "Timer <highlight>{$name}<end> has <highlight>{$timeLeft}<end> left.";
		$context->reply($msg);
	}

	/**
	 * Remove a timer
	 *
	 * You can only remove other peoples' timers if you are a moderator or higher
	 */
	#[NCA\HandlesCommand('timers')]
	#[NCA\Help\Group('timers')]
	public function timersRemoveCommand(CmdContext $context, PRemove $action, PUuid $id): void {
		$id = $id();
		$timer = $this->get($id);
		if ($timer === null) {
			$msg = "Could not find timer <highlight>#{$id}<end>.";
		} elseif ($timer->owner !== $context->char->name && !$this->accessManager->checkAccess($context->char->name, 'mod')) {
			$msg = 'You must own this timer or have moderator access in order to remove it.';
		} else {
			$event = new TimerDelEvent(timer: $timer);
			$this->eventManager->fireEvent($event);
			$this->remove($id);
			$msg = "Removed timer <highlight>{$timer->name}<end>.";
		}
		$context->reply($msg);
	}

	/** Add a new timer that triggers after &lt;duration&gt; */
	#[NCA\HandlesCommand('timers')]
	#[NCA\Help\Group('timers')]
	public function timersAddCommand(
		CmdContext $context,
		#[NCA\Str('add')] ?string $action,
		PDuration $duration,
		?string $name
	): void {
		$name ??= $context->char->name;

		$runTime = $duration->toSecs();
		$alertChannel = $this->getTimerAlertChannel($context);

		$sendto = $context->sendto;
		$origin = ($sendto instanceof MessageEmitter) ? $sendto->getChannelName() : null;
		$msg = $this->addTimer($context->char->name, $name, $runTime, $alertChannel, null, $origin);
		$sendto?->reply($msg);
		if (preg_match('/has been set for/', $msg)) {
			$sTimer = new SyncTimerEvent(
				name: $name,
				endtime: time() + $runTime,
				settime: time(),
				interval: null,
				owner: $context->char->name,
				forceSync: $context->forceSync,
			);
			$this->eventManager->fireEvent($sTimer);
		}
	}

	/** Show all currently running timers and repeating timers */
	#[NCA\HandlesCommand('timers')]
	#[NCA\Help\Group('timers')]
	public function timersListCommand(CmdContext $context): void {
		$timers = $this->getAllTimers();
		$count = count($timers);
		if ($count === 0) {
			$msg = 'No timers currently running.';
			$context->reply($msg);
			return;
		}
		$blob = '';
		// Sort timers by time until going off
		usort($timers, static function (Timer $a, Timer $b): int {
			return $a->endtime <=> $b->endtime; // @phpstan-ignore-line
		});
		foreach ($timers as $timer) {
			$timeLeft = '&lt;unknown&gt;';
			if (isset($timer->endtime)) {
				$timeLeft = Util::unixtimeToReadable($timer->endtime - time());
			}
			$name = $timer->name;
			$owner = $timer->owner;

			$remove_link = Text::makeChatcmd('Remove', "/tell <myname> timers rem {$timer->id}");

			$repeatingInfo = '';
			if ($timer->callback === 'timercontroller.repeatingTimerCallback') {
				$repeatingTimeString = Util::unixtimeToReadable((int)$timer->data);
				$repeatingInfo = " (Repeats every {$repeatingTimeString})";
			}

			$blob .= "Name: <highlight>{$name}<end> {$remove_link}\n";
			$blob .= "Time left: <highlight>{$timeLeft}<end> {$repeatingInfo}\n";
			$blob .= "Set by: <highlight>{$owner}<end>\n\n";
		}
		$msg = $this->text->makeBlob("Timers ({$count})", $blob);
		$context->reply($msg);
	}

	/**
	 * Generate alerts out of an alert specification
	 *
	 * @param string           $sender     Name of the player
	 * @param string           $name       Name of the alert
	 * @param int              $endTime    When to trigger the timer
	 * @param iterable<string> $alertTimes A list of alert times (human readable)
	 *
	 * @return list<Alert>
	 */
	public function generateAlerts(string $sender, string $name, int $endTime, iterable $alertTimes): array {
		$alerts = [];

		foreach ($alertTimes as $alertTime) {
			$time = Util::parseTime($alertTime);
			$timeString = Util::unixtimeToReadable($time);
			if ($endTime - $time > time()) {
				$alerts []= new Alert(
					message: "Reminder: Timer <highlight>{$name}<end> has <highlight>{$timeString}<end> left. [set by <highlight>{$sender}<end>]",
					time: $endTime - $time,
				);
			}
		}

		if ($endTime > time()) {
			$alerts []= new Alert(
				message: ($name === $sender)
					? "<highlight>{$sender}<end>, your timer has gone off."
					: "<highlight>{$sender}<end>, your timer named <highlight>{$name}<end> has gone off.",
				time: $endTime
			);
		}

		return $alerts;
	}

	/**
	 * Add a timer
	 *
	 * @param string       $sender  Name of the creator
	 * @param string       $name    Name of the timer
	 * @param int          $runTime When to trigger
	 * @param ?string      $channel Where to show (comma-separated)
	 * @param ?list<Alert> $alerts  List of alerts when to display things
	 *
	 * @return string Message to display
	 *
	 * @throws SQLException
	 */
	public function addTimer(string $sender, string $name, int $runTime, ?string $channel=null, ?array $alerts=null, ?string $origin=null): string {
		if ($name === '') {
			return '';
		}

		if ($this->get($name) !== null) {
			return "A timer named <highlight>{$name}<end> is already running.";
		}

		if ($runTime < 1) {
			return 'You must enter a valid time parameter.';
		}

		if (strlen($name) > 255) {
			return 'You cannot use timer names longer than 255 characters.';
		}

		$endTime = time() + $runTime;

		if ($alerts === null) {
			$alerts = $this->generateAlerts($sender, $name, $endTime, explode(' ', $this->timerAlertTimes));
		}

		$this->add($name, $sender, $channel, $alerts, 'timercontroller.timerCallback', null, $origin);

		$timerset = Util::unixtimeToReadable($runTime);
		return "Timer <highlight>{$name}<end> has been set for <highlight>{$timerset}<end>.";
	}

	/** @param list<Alert> $alerts */
	public function add(
		string $name,
		string $owner,
		?string $mode,
		array $alerts,
		string $callback,
		?string $data=null,
		?string $origin=null,
		?UuidInterface $id=null
	): Timer {
		usort($alerts, static function (Alert $a, Alert $b): int {
			return $a->time <=> $b->time;
		});

		/** @var ?Alert */
		$lastAlert = last($alerts);
		$timer = new Timer(
			id: $id,
			name: $name,
			owner: $owner,
			endtime: $lastAlert?->time,
			settime: time(),
			callback: $callback,
			data: $data,
			origin: $origin,
			mode: strlen($mode??'') ? $mode : null,
			alerts: $alerts,
		);

		$event = new TimerStartEvent(timer: $timer);

		$this->db->insert($timer);

		$this->timers[strtolower($name)] = $timer;
		$this->eventManager->fireEvent($event);
		return $timer;
	}

	public function remove(string|\Stringable $name): void {
		$name = (string)$name;
		if (!Uuid::isValid($name)) {
			$this->db->table(Timer::getTable())
				->whereIlike('name', $name)
				->delete();
			unset($this->timers[strtolower($name)]);
			return;
		}
		$this->db->table(Timer::getTable())->delete($name);
		foreach ($this->timers as $tName => $timer) {
			if ($timer->id->toString() === $name) {
				unset($this->timers[$tName]);
				return;
			}
		}
	}

	public function get(\Stringable|string $name): ?Timer {
		$timer = $this->timers[strtolower((string)$name)] ?? null;
		if (isset($timer)) {
			return $timer;
		}
		foreach ($this->timers as $tName => $curTimer) {
			if ($curTimer->id->toString() === (string)$name) {
				return $curTimer;
			}
		}
		return null;
	}

	/** @return array<string,Timer> */
	public function getAllTimers(): array {
		return $this->timers;
	}

	#[NCA\Event(
		name: SyncTimerEvent::EVENT_MASK,
		description: 'Sync external timers to local timers'
	)]
	public function syncExtTimers(SyncTimerEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$timerName = $event->name;
		$i = 1;
		while ($this->get($timerName) !== null) {
			$timerName = $event->name . '-' . (++$i);
		}
		$event->name = $timerName;

		$alerts = $this->generateAlerts($event->owner, $event->name, $event->endtime, explode(' ', $this->timerAlertTimes));
		if (isset($event->interval)) {
			$this->add($event->name, $event->owner, null, $alerts, 'timercontroller.repeatingTimerCallback', (string)$event->interval);
		} else {
			$this->add($event->name, $event->owner, null, $alerts, 'timercontroller.timerCallback');
		}
	}

	protected function getTimerAlertChannel(CmdContext $context): string {
		// Timers via tell always create tell alerts only
		if (isset($context->source) && strncmp($context->source, 'aotell(', 7) === 0) {
			return 'msg';
		}
		return '';
	}
}
