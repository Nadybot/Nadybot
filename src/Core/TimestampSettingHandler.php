<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\Attributes as NCA;

#[NCA\SettingHandler("timestamp")]
class TimestampSettingHandler extends SettingHandler {
	#[NCA\Inject]
	public Util $util;

	/** @inheritDoc */
	public function getDescription(): string {
		$msg = "For this setting you can set any positive integer.\n";
		$msg .= "To change this setting: \n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>number</i><end>\n\n";
		return $msg;
	}

	/**
	 * @inheritDoc
	 *
	 * @throws Exception when not a number
	 */
	public function save(string $newValue): string {
		if (preg_match("/^[0-9]+$/i", $newValue)) {
			return $newValue;
		}
		throw new Exception("You must enter a positive integer for this setting.");
	}

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		$unixTime = (int)($this->getData()->value??"0");
		if ($unixTime === 0) {
			return "<grey>&lt;empty&gt;<end>";
		}
		return "<highlight>" . $this->util->date($unixTime) . "<end>";
	}
}
