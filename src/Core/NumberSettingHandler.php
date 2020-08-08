<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;

class NumberSettingHandler extends SettingHandler {

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		$msg = "For this setting you can set any positive integer.\n";
		$msg .= "To change this setting: \n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>number</i><end>\n\n";
		return $msg;
	}
	
	/**
	 * @inheritDoc
	 * @throws Exception when not a number
	 */
	public function save(string $newValue): string {
		if (preg_match("/^[0-9]+$/i", $newValue)) {
			return $newValue;
		}
		throw new Exception("You must enter a positive integer for this setting.");
	}
}
