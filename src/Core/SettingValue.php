<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Setting;

class SettingValue {
	public ?string $value;

	public string $type;

	public function __construct(Setting $setting) {
		$this->value = $setting->value;
		if (isset($setting->intoptions) && strlen($setting->intoptions)) {
			$this->type = "string";
			if (preg_match('/^[\d;]+$/', $setting->intoptions)) {
				$this->type = "number";
			}
			if ($setting->options === "true;false") {
				$this->type = "bool";
			}
		} else {
			$this->type = $setting->type ?? "string";
		}
	}

	/** @return null|bool|int|string|mixed[] */
	public function typed(): null|bool|int|string|array {
		if (str_ends_with($this->type, '[]')) {
			if (is_null($this->value) || !strlen($this->value)) {
				return [];
			}
			$type = substr($this->type, 0, -2);
			return array_map(
				fn ($value) => $this->typeValue($type, $value),
				explode("|", $this->value)
			);
		}
		return $this->typeValue($this->type, $this->value);
	}

	/** @return null|bool|int|string */
	private function typeValue(string $type, ?string $value): null|bool|int|string {
		if (is_null($value)) {
			return null;
		}
		if (in_array($type, ['number', 'time'])) {
			return (int)$value;
		}
		if ($type === "bool") {
			return (bool)$value;
		}
		return $value;
	}
}
