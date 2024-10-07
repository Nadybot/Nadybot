<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\EventCfg;

class ModuleEventConfig {
	/**
	 * @param string $event       The event for this module
	 * @param string $handler     The function handling this event
	 * @param string $description What is supposed to happen when this event occurs?
	 * @param bool   $enabled     Is the event handler turned on?
	 */
	public function __construct(
		public string $event,
		public string $handler,
		public string $description,
		public bool $enabled,
	) {
	}

	public static function fromEventCfg(EventCfg $cfg): self {
		return new self(
			event: $cfg->type,
			description: $cfg->description ?? 'no description available',
			handler: $cfg->file,
			enabled: (bool)$cfg->status,
		);
	}
}
