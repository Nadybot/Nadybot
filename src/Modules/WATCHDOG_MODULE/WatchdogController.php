<?php declare(strict_types=1);

namespace Nadybot\Modules\WATCHDOG_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	Instance,
};

/**
 * Authors:
 *  - Nadyita (RK5)
 */
#[NCA\Instance]
class WatchdogController extends Instance {

		#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Event(
		name: "timer(10sec)",
		description: "Periodically touch an alive-file"
	)]
	public function touchAliveFile(): void {
		touch(sys_get_temp_dir().'/alive.'.$this->config->name.'.'.$this->config->dimension);
	}
}
