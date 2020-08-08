<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;

/**
 * Class to represent a time setting for BudaBot
 */
class TimeSettingHandler extends SettingHandler {
	/** @Inject */
	public Util $util;

	/**
	 * @inheritDoc
	 */
	public function displayValue(): string {
		return "<highlight>" . $this->util->unixtimeToReadable((int)$this->row->value) . "<end>";
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		$msg = "For this setting you must enter a time value. See <a href='chatcmd:///tell <myname> help budatime'>budatime</a> for info on the format of the 'time' parameter.\n\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>time</i><end>\n\n";
		return $msg;
	}

	/**
	 * @inheritDoc
	 *
	 * @throws \Exception when the time is invalid
	 */
	public function save(string $newValue): string {
		if ($this->util->isInteger($newValue)) {
			return $newValue;
		}
		$time = $this->util->parseTime($newValue);
		if ($time > 0) {
			return (string)$time;
		}
		throw new Exception("This is not a valid time for this setting.");
	}
}
