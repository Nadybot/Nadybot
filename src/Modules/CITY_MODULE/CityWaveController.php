<?php

namespace Budabot\Modules\CITY_MODULE;

use Budabot\Core\Event;
use stdClass;
use Exception;

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
	public $moduleName;
	
	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @var \Budabot\Core\CommandAlias $commandAlias
	 * @Inject
	 */
	public $commandAlias;
	
	/**
	 * @var \Budabot\User\Modules\TIMERS_MODULE\TimerController $timerController
	 * @Inject
	 */
	public $timerController;
	
	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;
	
	/**
	 * @var \Budabot\Core\SettingObject $setting
	 * @Inject
	 */
	public $setting;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	const TIMER_NAME = "City Raid";
	
	/**
	 * @Setup
	 */
	public function setup() {
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
			'org;priv;both;none',
			'',
			'mod'
		);
		$this->settingManager->registerChangeListener('city_wave_times', array($this, 'changeWaveTimes'));
	}
	
	public function changeWaveTimes($settingName, $oldValue, $newValue, $data) {
		$alertTimes = explode(' ', $newValue);
		if (count($alertTimes) != 9) {
			throw new Exception("Error saving setting: must have 9 spawn times. For more info type !help city_wave_times.");
		}
		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			if ($time == 0) {
				// invalid time
				throw new Exception("Error saving setting: invalid alert time('$alertTime'). For more info type !help city_wave_times.");
			}
		}
	}
	
	/**
	 * @HandlesCommand("citywave")
	 * @Matches("/^citywave start$/i")
	 */
	public function citywaveStartCommand($message, $channel, $sender, $sendto, $args) {
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
	public function citywaveStopCommand($message, $channel, $sender, $sendto, $args) {
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
	public function citywaveCommand($message, $channel, $sender, $sendto, $args) {
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
	public function autoStartWaveCounterEvent(Event $eventObj) {
		if (preg_match("/^Your city in (.+) has been targeted by hostile forces.$/i", $eventObj->message)) {
			$this->startWaveCounter();
		}
	}
	
	public function getWave() {
		$timer = $this->timerController->get(self::TIMER_NAME);
		if ($timer === null) {
			return null;
		} else {
			return $timer->alerts[0]->wave;
		}
	}

	public function announce($msg, $announceWhere=null) {
		if ($announceWhere === null) {
			$announceWhere = $this->settingManager->get('city_wave_announce');
		}
		if ($announceWhere === "both" || $announceWhere === "org") {
			$this->chatBot->sendGuild($msg, true);
		}
		if ($announceWhere === "both" || $announceWhere === "priv") {
			$this->chatBot->sendPrivate($msg, true);
		}
	}

	public function sendAlertMessage($timer, $alert) {
		$this->announce($alert->message, $timer->mode);
	}
	
	public function startWaveCounter($name=null) {
		if ($name === null) {
			$this->announce("Wave counter started.");
		} else {
			$this->announce("Wave counter started by $name.");
		}
		$lastTime = time();
		$wave = 1;
		$alerts = array();
		$alertTimes = explode(' ', $this->setting->city_wave_times);
		foreach ($alertTimes as $alertTime) {
			$time = $this->util->parseTime($alertTime);
			$lastTime += $time;

			$alert = new stdClass;
			if ($wave == 9) {
				$alert->message = "General Incoming.";
			} else {
				$alert->message = "Wave $wave incoming.";
			}
			$alert->time = $lastTime;
			$alert->wave = $wave;
			$alerts []= $alert;

			$wave++;
		}
		$this->timerController->remove(self::TIMER_NAME);
		$announceWhere = $this->settingManager->get('city_wave_announce');
		$this->timerController->add(
			self::TIMER_NAME,
			$this->chatBot->vars['name'],
			$announceWhere,
			$alerts,
			'citywavecontroller.sendAlertMessage'
		);
	}
}
