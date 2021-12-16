<?php declare(strict_types=1);

namespace Nadybot\Modules\WATCHDOG_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\ConfigFile;

/**
 * Authors:
 *  - Nadyita (RK5)
 */
#[NCA\Instance]
class WatchdogController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

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
