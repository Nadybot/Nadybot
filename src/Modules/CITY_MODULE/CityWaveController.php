<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Exception;
use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	Event,
	Nadybot,
	SettingManager,
	SettingObject,
	Util,
};
use Nadybot\Modules\TIMERS_MODULE\{
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
 */
class CityWaveController {

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
	public SettingManager $settingManager;
	
	/** @Inject */
	public SettingObject $setting;
	
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
		$this->settingManager->add(
			$this->moduleName,
			'city_wave_announce',
			'Where to show city waves events',
			'edit',
			'text',
			'org',
			'org;priv;org,priv;none',
			'',
			'mod'
		);
		$this->settingManager->registerChangeListener(
			'city_wave_times',
			[$this, 'changeWaveTimes']
		);
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
	 * @Matches("/^citywave start$/i")
	 */
	public function citywaveStartCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$wave = $this->getWave();
		if ($wave !== null) {
			$sendto->reply("A raid is already in progress.");
		} else {
			$this->startWaveCounter($sender);
		}
	}

	/**
	 * @HandlesCommand("citywave")
	 * @Matches("/^citywave stop$/i")
	 */
	public function citywaveStopCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$wave = $this->getWave();
		if ($wave === null) {
			$msg = "There is no raid in progress at this time.";
		} else {
			$this->timerController->remove(self::TIMER_NAME);
			$msg = "Wave counter stopped by $sender.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("citywave")
	 * @Matches("/^citywave$/i")
	 */
	public function citywaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$wave = $this->getWave();
		if ($wave === null) {
			$msg = "There is no raid in progress at this time.";
		} elseif ($wave == 9) {
			$msg = "Waiting for General.";
		} else {
			$msg = "Waiting for wave $wave.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @Event("guild")
	 * @Description("Starts a wave counter when cloak is lowered")
	 */
	public function autoStartWaveCounterEvent(Event $eventObj): void {
		if (preg_match("/^Your city in (.+) has been targeted by hostile forces.$/i", $eventObj->message)) {
			$this->startWaveCounter();
		}
	}
	
	public function getWave(): ?int {
		$timer = $this->timerController->get(self::TIMER_NAME);
		if ($timer === null) {
			return null;
		}
		return $timer->alerts[0]->wave;
	}

	public function announce(string $msg, ?string $announceWhere=null): void {
		if ($announceWhere === null) {
			$announceWhere = $this->settingManager->getString('city_wave_announce');
		}
		$channels = explode(",", $announceWhere);
		foreach ($channels as $channel) {
			if ($channel === "org") {
				$this->chatBot->sendGuild($msg, true);
			}
			if ($channel === "priv") {
				$this->chatBot->sendPrivate($msg, true);
			}
		}
	}

	public function sendAlertMessage(Timer $timer, WaveAlert $alert): void {
		$this->announce($alert->message, $timer->mode);
	}
	
	public function startWaveCounter(string $name=null): void {
		if ($name === null) {
			$this->announce("Wave counter started.");
		} else {
			$this->announce("Wave counter started by $name.");
		}
		$lastTime = time();
		$wave = 1;
		$alerts = [];
		$alertTimes = explode(' ', $this->setting->city_wave_times);
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
		$announceWhere = $this->settingManager->getString('city_wave_announce');
		$this->timerController->add(
			self::TIMER_NAME,
			$this->chatBot->vars['name'],
			$announceWhere,
			$alerts,
			'timercontroller.timerCallback'
		);
	}
}
