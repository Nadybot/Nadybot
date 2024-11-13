<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use Nadybot\Core\{AccessManager, Attributes as NCA};

/**
 * Class to represent a setting with a secret text value for NadyBot
 */
#[NCA\SettingHandler('secret')]
class SecretSettingHandler extends TextSettingHandler {
	#[NCA\Inject]
	private AccessManager $accessManager;

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		$displayValue = parent::displayValue($sender);
		if ($displayValue === '<highlight><end>') {
			$displayValue = '<grey>&lt;empty&gt;<end>';
		}
		if (!$this->accessManager->checkAccess($sender, $this->row->admin??'all')) {
			return '<highlight>*********<end>';
		}
		return $displayValue;
	}
}
