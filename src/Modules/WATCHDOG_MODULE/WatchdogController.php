<?php declare(strict_types=1);

namespace Nadybot\Modules\WATCHDOG_MODULE;

use Nadybot\Core\Nadybot;

/**
 * Authors:
 *  - Nadyita (RK5)
 *
 * @Instance
 */
class WatchdogController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/**
	 * @Event(name="timer(10sec)",
	 * 	description="Periodically touch an alive-file")
	 */
	public function touchAliveFile(): void {
		touch(sys_get_temp_dir().'/alive.'.$this->chatBot->vars['name'].'.'.$this->chatBot->vars['dimension']);
	}
}
