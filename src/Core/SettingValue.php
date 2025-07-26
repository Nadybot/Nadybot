<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Setting;

class SettingValue {
	/** The string value of this setting */
	public ?string $value;

	/** The type of this setting */
	public string $type;

	/** @param Setting $setting The database setting data */
	public function __construct(Setting $setting) {
		$this->value = $setting->value;
		if (isset($setting->intoptions) && strlen($setting->intoptions)) {
			$this->type = 'string';
			if (Safe::pregMatches('/^[\d;]+$/', $setting->intoptions)) {
				$this->type = 'number';
			}
			if ($setting->options === 'true;false') {
				$this->type = 'bool';
			}
		} else {
			$this->type = $setting->type ?? 'string';
		}
	}

	/**
	 * Return a typed value for this setting
	 *
	 * @return null|bool|int|string|list<null|bool|int|string>
	 */
	public function typed(): null|bool|int|string|array {
		if (str_ends_with($this->type, '[]')) {
			if (is_null($this->value) || !strlen($this->value)) {
				return [];
			}
			$type = substr($this->type, 0, -2);
			return array_map(
				fn (string $value): null|bool|int|string => $this->typeValue($type, $value),
				explode('|', $this->value)
			);
		}
		return $this->typeValue($this->type, $this->value);
	}

	/**
	 * Cast a given value to a given type
	 *
	 * @param string  $type  The type of the value
	 * @param ?string $value The string value to type cast
	 *
	 * @return null|bool|int|string The typed result
	 */
	private function typeValue(string $type, ?string $value): null|bool|int|string {
		if (is_null($value)) {
			return null;
		}
		if (in_array($type, ['number', 'time'], true)) {
			return (int)$value;
		}
		if ($type === 'bool') {
			return (bool)$value;
		}
		return $value;
	}
}
