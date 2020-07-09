<?php

namespace Budabot\Core;

use Exception;

/**
 * Class to represent a setting with a text value for BudaBot
 */
class TextSettingHandler extends SettingHandler {

	/**
	 * Construct a new handler out of a given database row
	 *
	 * @param \Budabot\Core\DBRow $row The database row
	 * @return self
	 */
	public function __construct(DBRow $row) {
		parent::__construct($row);
	}

	/**
	 * Describe the valid values for this setting
	 *
	 * @return string
	 */
	public function getDescription() {
		$msg = "For this setting you can enter any text you want (max. 255 chararacters).\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>text</i><end>\n\n";
		return $msg;
	}

	/**
	 * Change this setting
	 *
	 * @param string $newValue The new value
	 * @return string The new value
	 * @throws \Exception when the string is too long
	 */
	public function save($newValue) {
		if (strlen($newValue) > 255) {
			throw new Exception("Your text can not be longer than 255 characters.");
		} else {
			return $newValue;
		}
	}
}
