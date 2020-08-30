<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Event;
use Nadybot\Core\Nadybot;
use Nadybot\Modules\TIMERS_MODULE\TimerController;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class OSController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public TimerController $timerController;
	
	/**
	 * @Event("orgmsg")
	 * @Description("Sets a timer when an OS/AS is launched")
	 */
	public function osTimerEvent(Event $eventObj): void {
		// create a timer for 15m when an OS/AS is launched (so org knows when they can launch again)
		// [Org Msg] Blammo! Player has launched an orbital attack!

		if (preg_match("/^Blammo! (.+) has launched an orbital attack!$/i", $eventObj->message, $arr)) {
			$orgName = $this->chatBot->vars["my_guild"];

			$launcher = $arr[1];

			for ($i = 1; $i <= 10; $i++) {
				$name = "$orgName OS/AS $i";
				if ($this->timerController->get($name) === null) {
					$runTime = 15 * 60; // set timer for 15 minutes
					$msg = $this->timerController->addTimer($launcher, $name, $runTime, 'guild');
					$this->chatBot->sendGuild($msg);
					break;
				}
			}
		}
	}
}
