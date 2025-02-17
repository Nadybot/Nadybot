<?php declare(strict_types=1);

namespace Nadybot\Modules\WATCHDOG_MODULE;

use Nadybot\Core\Filesystem;
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
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	/** Periodically touch an alive-file */
	#[NCA\Timer(interval: '10sec')]
	public function touchAliveFile(): void {
		$this->fs->touch(sys_get_temp_dir().'/alive.'.$this->config->main->character.'.'.$this->config->main->dimension);
	}
}
