<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Exception;

/**
 * Class to represent a setting with a text value for NadyBot
 */
#[NCA\SettingHandler("text")]
class TextSettingHandler extends SettingHandler {
	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		$msg = "For this setting you can enter any text you want (max. 255 chararacters).\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>text</i><end>\n\n";
		return $msg;
	}

	/**
	 * @inheritDoc
	 *
	 * @throws \Exception when the string is too long
	 */
	public function save(string $newValue): string {
		if (strlen($newValue) > 255) {
			throw new Exception("Your text can not be longer than 255 characters.");
		}
		return $newValue;
	}

	/**
	 * Get a displayable representation of the setting
	 */
	public function displayValue(string $sender): string {
		$displayValue = parent::displayValue($sender);
		if ($displayValue === "<highlight><end>") {
			$displayValue = "<grey>&lt;empty&gt;<end>";
		}
		return $displayValue;
	}
}
