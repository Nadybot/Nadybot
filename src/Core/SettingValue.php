<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Setting;

class SettingValue {
	public string $value;

	public string $type;

	public function __construct(Setting $setting) {
		$this->value = $setting->value;
		if (strlen($setting->intoptions??"")) {
			$this->type = "number";
			if ($setting->options === "true;false") {
				$this->type = "bool";
			}
		} else {
			$this->type = $setting->type;
		}
	}

	public function typed() {
		if (in_array($this->type, ['number', 'time'])) {
			return (int)$this->value;
		}
		if ($this->type === "bool") {
			return (bool)$this->value;
		}
		return (string)$this->value;
	}
}
