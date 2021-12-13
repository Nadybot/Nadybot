<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Exception;
use Nadybot\Core\{
	AOChatEvent,
	CmdContext,
	CommandAlias,
	Event,
	EventManager,
	MessageEmitter,
	MessageHub,
	Nadybot,
	SettingManager,
	Util,
};
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\TIMERS_MODULE\{
	Alert,
	TimerController,
	Timer,
};

/**
 * @author Funkman (RK2)
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'citywave',
 *		accessLevel = 'guild',
 *		description = 'Shows/Starts/Stops the current city wave',
 *		help        = 'wavecounter.txt'
 *	)
 *	@ProvidesEvent("cityraid(start)")
 *	@ProvidesEvent("cityraid(wave)")
 *	@ProvidesEvent("cityraid(end)")
 */
class CityWaveController implements MessageEmitter {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public TimerController $timerController;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public Util $util;

	public const TIMER_NAME = "City Raid";

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "citywave start", "startwave");
		$this->commandAlias->register($this->moduleName, "citywave stop", "stopwave");

		$this->settingManager->add(
			$this->moduleName,
			'city_wave_times',
			'Times to display timer alerts',
			'edit',
			'text',
			'105s 150s 90s 120s 120s 120s 120s 120s 120s',
			'105s 150s 90s 120s 120s 120s 120s 120s 120s',
			'',
			'mod',
			'city_wave_times.txt'
		);
		$this->settingManager->registerChangeListener(
			'city_wave_times',
			[$this, 'changeWaveTimes']
		);
		$this->messageHub->registerMessageEmitter($this);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(city-wave)";
	}

	public function sendWaveMessage(string $message): void {
		$e = new RoutableMessage($message);
		$e->prependPath(new Source(
			Source::SYSTEM,
			"city-wave"
		));
		$this->messageHub->handle($e);
	}

	public function changeWaveTimes(string $settingName, string $oldValue, string $newValue, $data): void {
		$alertTimes = explode(' ', $newValue);
		if (count($alertTimes) !== 9) {
			throw new Exception("Error saving setting: must have 9 spawn times. For more info type !help city_wave_times.");
		}
		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			if ($time === 0) {
				// invalid time
				throw new Exception("Error saving setting: invalid alert time('$alertTime'). For more info type !help city_wave_times.");
			}
		}
	}

	/**
	 * @HandlesCommand("citywave")
	 * @Mask $action start
	 */
	public function citywaveStartCommand(CmdContext $context, string $action): void {
		$wave = $this->getWave();
		if ($wave !== null) {
			$context->reply("A raid is already in progress.");
		} else {
			$this->startWaveCounter($context->char->name);
		}
	}

	/**
	 * @HandlesCommand("citywave")
	 * @Mask $action stop
	 */
	public function citywaveStopCommand(CmdContext $context, string $action): void {
		$wave = $this->getWave();
		if ($wave === null) {
			$msg = "There is no raid in progress at this time.";
		} else {
			$this->timerController->remove(self::TIMER_NAME);
			$msg = "Wave counter stopped by {$context->char->name}.";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("citywave")
	 */
	public function citywaveCommand(CmdContext $context): void {
		$wave = $this->getWave();
		if ($wave === null) {
			$msg = "There is no raid in progress at this time.";
		} elseif ($wave == 9) {
			$msg = "Waiting for General.";
		} else {
			$msg = "Waiting for wave $wave.";
		}
		$context->reply($msg);
	}

	/**
	 * @Event(name="guild",
	 * 	description="Starts a wave counter when cloak is lowered")
	 */
	public function autoStartWaveCounterEvent(AOChatEvent $eventObj): void {
		if (preg_match("/^Your city in (.+) has been targeted by hostile forces.$/i", $eventObj->message)) {
			$this->startWaveCounter();
		}
	}

	public function getWave(): ?int {
		$timer = $this->timerController->get(self::TIMER_NAME);
		if ($timer === null || !isset($timer->alerts[0]->wave)) {
			return null;
		}
		return $timer->alerts[0]->wave;
	}

	public function sendAlertMessage(Timer $timer, WaveAlert $alert): void {
		$this->sendWaveMessage($alert->message);
		$event = new CityWaveEvent();
		$event->type = "cityraid(wave)";
		$event->wave = $alert->wave;
		if ($alert->wave === 9) {
			$event->type = "cityraid(end)";
		}
		$this->eventManager->fireEvent($event);
	}

	public function startWaveCounter(string $name=null): void {
		$event = new CityWaveEvent();
		$event->type = "cityraid(start)";
		$this->eventManager->fireEvent($event);

		if ($name === null) {
			$this->sendWaveMessage("Wave counter started.");
		} else {
			$this->sendWaveMessage("Wave counter started by $name.");
		}
		$lastTime = time();
		$wave = 1;
		$alerts = [];
		$alertTimes = explode(' ', $this->settingManager->getString("city_wave_times")??"");
		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			$lastTime += $time;

			$alert = new WaveAlert();
			$alert->message = "Wave $wave incoming.";
			if ($wave === 9) {
				$alert->message = "General Incoming.";
			}
			$alert->time = $lastTime;
			$alert->wave = $wave;
			$alerts []= $alert;

			$wave++;
		}
		$this->timerController->remove(self::TIMER_NAME);
		$this->timerController->add(
			self::TIMER_NAME,
			$this->chatBot->char->name,
			"none",
			$alerts,
			'citywavecontroller.timerCallback'
		);
	}

	public function timerCallback(Timer $timer, Alert $alert): void {
		$this->sendWaveMessage($alert->message);
	}
}
