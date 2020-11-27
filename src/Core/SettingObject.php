<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance("setting")
 */
class SettingObject {
	/** @Inject */
	public SettingManager $settingManager;

	public function __set(string $name, $value): void {
		$this->settingManager->save($name, $value);
	}

	public function __get(string $name) {
		return $this->settingManager->get($name);
	}
}
