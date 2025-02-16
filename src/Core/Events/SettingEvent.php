<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Types\EventInterface;
use Nadybot\Core\{Attributes as NCA, SettingValue};

#[NCA\Event(mask: 'setting(*)')]
class SettingEvent implements EventInterface {
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
