<?php declare(strict_types=1);

namespace Nadybot\Core;

class SettingEvent extends Event {
	public const EVENT_MASK = 'setting(*)';

	public function __construct(
		public string $setting,
		public SettingValue $oldValue,
		public SettingValue $newValue,
	) {
		$this->type = "setting({$setting})";
	}
}
