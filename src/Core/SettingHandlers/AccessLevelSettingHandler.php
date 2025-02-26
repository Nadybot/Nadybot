<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\CONFIG\ConfigController;
use Nadybot\Core\Types\AccessLevel;

/**
 * Class to represent a setting with an access level value for NadyBot
 */
#[NCA\SettingHandler('rank')]
class AccessLevelSettingHandler extends SettingHandler {
	#[NCA\Inject]
	private ConfigController $configController;

	/** @inheritDoc */
	public function getDescription(): string {
		$msg = 'For this setting you need to choose one of the available '.
			"access levels:\n\n";
		$ranks = $this->configController->getValidAccessLevels();
		foreach ($ranks as $rank) {
			if ($rank->enabled) {
				$msg .= "<tab><a href='chatcmd:///tell <myname> settings save {$this->row->name} {$rank->value}'>{$rank->name}</a>\n";
			}
		}
		return $msg;
	}

	/** @throws \Exception when the rank is invalid */
	public function save(string $newValue): string {
		$accessLevel = AccessLevel::tryFromName($newValue);
		if (!isset($accessLevel)) {
			throw new Exception("<highlight>{$newValue}<end> is not a valid access level.");
		}
		return $accessLevel->value;
	}

	/** @inheritDoc */
	public function displayValue(string $sender): string {
		$value = $this->row->value ?? 'all';
		$accessLevel = AccessLevel::fromName($value);
		return "<highlight>{$accessLevel->displayNameUC()}<end>";
	}
}
