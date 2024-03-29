<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Exception;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	EventManager,
	MessageEmitter,
	MessageHub,
	ModuleInstance,
	Nadybot,
	Routing\RoutableMessage,
	Routing\Source,
	Util,
};
use Nadybot\Modules\TIMERS_MODULE\{
	Alert,
	Timer,
	TimerController,
};

/**
 * @author Funkman (RK2)
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "citywave",
		accessLevel: "guild",
		description: "Shows/Starts/Stops the current city wave",
	),
	NCA\ProvidesEvent("cityraid(start)"),
	NCA\ProvidesEvent("cityraid(wave)"),
	NCA\ProvidesEvent("cityraid(end)")
]
class CityWaveController extends ModuleInstance implements MessageEmitter {
	public const TIMER_NAME = "City Raid";
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public TimerController $timerController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public Util $util;

	/** Times to display timer alerts */
	#[NCA\Setting\Text(
		options: ["105s 150s 90s 120s 120s 120s 120s 120s 120s"],
		help: 'city_wave_times.txt'
	)]
	public string $cityWaveTimes = '105s 150s 90s 120s 120s 120s 120s 120s 120s';

	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "citywave start", "startwave");
		$this->commandAlias->register($this->moduleName, "citywave stop", "stopwave");

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

	#[NCA\SettingChangeHandler("city_wave_times")]
	public function changeWaveTimes(string $settingName, string $oldValue, string $newValue, mixed $data): void {
		$alertTimes = explode(' ', $newValue);
		if (count($alertTimes) !== 9) {
			throw new Exception("Error saving setting: must have 9 spawn times. For more info type !help city_wave_times.");
		}
		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			if ($time === 0) {
				// invalid time
				throw new Exception("Error saving setting: invalid alert time('{$alertTime}'). For more info type !help city_wave_times.");
			}
		}
	}

	/** Manually start the wave timer */
	#[NCA\HandlesCommand("citywave")]
	#[NCA\Help\Epilogue(
		"Note: the Wave Counter will start and stop automatically under normal circumstances, ".
		"but the start and stop functions are provided just in case."
	)]
	public function citywaveStartCommand(CmdContext $context, #[NCA\Str("start")] string $action): void {
		$wave = $this->getWave();
		if ($wave !== null) {
			$context->reply("A raid is already in progress.");
		} else {
			$this->startWaveCounter($context->char->name);
		}
	}

	/** Manually stop the wave timer */
	#[NCA\HandlesCommand("citywave")]
	public function citywaveStopCommand(CmdContext $context, #[NCA\Str("stop")] string $action): void {
		$wave = $this->getWave();
		if ($wave === null) {
			$msg = "There is no raid in progress at this time.";
		} else {
			$this->timerController->remove(self::TIMER_NAME);
			$msg = "Wave counter stopped by {$context->char->name}.";
		}
		$context->reply($msg);
	}

	/** Show the current wave */
	#[NCA\HandlesCommand("citywave")]
	public function citywaveCommand(CmdContext $context): void {
		$wave = $this->getWave();
		if ($wave === null) {
			$msg = "There is no raid in progress at this time.";
		} elseif ($wave == 9) {
			$msg = "Waiting for General.";
		} else {
			$msg = "Waiting for wave {$wave}.";
		}
		$context->reply($msg);
	}

	#[NCA\Event(
		name: "guild",
		description: "Starts a wave counter when cloak is lowered"
	)]
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

	public function startWaveCounter(?string $name=null): void {
		$event = new CityWaveEvent();
		$event->type = "cityraid(start)";
		$this->eventManager->fireEvent($event);

		if ($name === null) {
			$this->sendWaveMessage("Wave counter started.");
		} else {
			$this->sendWaveMessage("Wave counter started by {$name}.");
		}
		$lastTime = time();
		$wave = 1;
		$alerts = [];
		$alertTimes = explode(' ', $this->cityWaveTimes);
		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			$lastTime += $time;

			$alert = new WaveAlert();
			$alert->message = "Wave {$wave} incoming.";
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
