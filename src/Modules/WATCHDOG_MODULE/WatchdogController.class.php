<?php declare(strict_types=1);

namespace Nadybot\User\Modules;

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
	 * @Event("timer(10sec)")
	 * @Description("Periodically touch an alive-file")
	 */
	public function touchAliveFile(): void {
		touch(sys_get_temp_dir().'/alive.'.$this->chatBot->vars['name'].'.'.$this->chatBot->vars['dimension']);
	}
}
