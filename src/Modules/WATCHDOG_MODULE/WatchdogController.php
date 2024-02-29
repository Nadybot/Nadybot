<?php declare(strict_types=1);

namespace Nadybot\Modules\WATCHDOG_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	ModuleInstance,
};

/**
 * Authors:
 *  - Nadyita (RK5)
 */
#[NCA\Instance]
class WatchdogController extends ModuleInstance {
	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Event(
		name: "timer(10sec)",
		description: "Periodically touch an alive-file"
	)]
	public function touchAliveFile(): void {
		\Safe\touch(sys_get_temp_dir().'/alive.'.$this->config->name.'.'.$this->config->dimension);
	}
}
