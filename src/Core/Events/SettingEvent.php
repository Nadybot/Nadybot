<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\{Attributes as NCA, SettingValue};
use Nadybot\Core\Types\EventInterface;

/** Fired whenever a setting was changed */
#[NCA\Event(mask: 'setting(*)')]
class SettingEvent implements EventInterface {
	/**
	 * @param string       $setting  The name of  the setting
	 * @param SettingValue $oldValue The old value
	 * @param SettingValue $newValue The new value
	 */
	public function __construct(
		public string $setting,
		public SettingValue $oldValue,
		public SettingValue $newValue,
	) {
	}

	public function getEvent(): string {
		return "setting({$this->setting})";
	}
}
