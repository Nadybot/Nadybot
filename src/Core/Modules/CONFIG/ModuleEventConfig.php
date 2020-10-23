<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\EventCfg;

class ModuleEventConfig {
	/** The event for this module */
	public string $event;

	/** What is supposed to happed when this event occurs? */
	public string $description;

	/** Is the event handler turned on? */
	public bool $enabled;

	public function __construct(EventCfg $cfg) {
		$this->event = $cfg->type;
		$this->description = $cfg->description ?? "no description available";
		$this->enabled = (bool)$cfg->status;
	}
}
