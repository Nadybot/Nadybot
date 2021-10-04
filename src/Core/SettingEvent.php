<?php declare(strict_types=1);

namespace Nadybot\Core;

class SettingEvent extends Event {
	public string $setting;
	public SettingValue $oldValue;
	public SettingValue $newValue;
}
