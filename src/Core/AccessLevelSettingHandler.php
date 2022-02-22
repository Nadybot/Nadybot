<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\Modules\CONFIG\ConfigController;

/**
 * Class to represent a setting with an access level value for NadyBot
 */
#[NCA\SettingHandler("rank")]
class AccessLevelSettingHandler extends SettingHandler {

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public ConfigController $configController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		$msg = "For this setting you need to choose one of the available ".
			"access levels:\n\n";
		$ranks = $this->configController->getValidAccessLevels();
		foreach ($ranks as $rank) {
			if ($rank->enabled) {
				$msg .= "<tab><a href='chatcmd:///tell <myname> settings save {$this->row->name} {$rank->value}'>{$rank->name}</a>\n";
			}
		}
		return $msg;
	}

	/**
	 * @throws \Exception when the rank is invalid
	 */
	public function save(string $newValue): string {
		$accessLevels = $this->accessManager->getAccessLevels();
		if (!isset($accessLevels[$newValue])) {
			throw new Exception("<highlight>$newValue<end> is not a valid access level.");
		}
		return $newValue;
	}

	public function displayValue(string $sender): string {
		$value = $this->row->value ?? "all";
		$rank = ucfirst(strtolower($this->accessManager->getDisplayName($value)));
		return "<highlight>{$rank}<end>";
	}
}
