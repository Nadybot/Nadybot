<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Event\OrgMsgChannelMsgEvent;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	ModuleInstance,
	Nadybot,
	Safe,
};
use Nadybot\Modules\TIMERS_MODULE\TimerController;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class OSController extends ModuleInstance {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private TimerController $timerController;

	#[NCA\Event(
		name: OrgMsgChannelMsgEvent::EVENT_MASK,
		description: 'Sets a timer when an OS/AS is launched'
	)]
	public function osTimerEvent(OrgMsgChannelMsgEvent $eventObj): void {
		// create a timer for 15m when an OS/AS is launched (so org knows when they can launch again)
		// [Org Msg] Blammo! Player has launched an orbital attack!

		if (count($arr = Safe::pregMatch('/^Blammo! (.+) has launched an orbital attack!$/i', $eventObj->message)) === 2) {
			$launcher = $arr[1];

			for ($i = 1; $i <= 10; $i++) {
				$name = "{$this->config->general->orgName} OS/AS {$i}";
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
