<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\Attributes as NCA;

/**
 * Class to represent a time-or-off setting for NadyBot
 */
#[NCA\SettingHandler("time_or_off")]
class TimeOrOffSettingHandler extends SettingHandler {
	#[NCA\Inject]
	public Util $util;

	/** @inheritDoc */
	public function displayValue(string $sender): string {
		if ($this->row->value === "0" || $this->row->value === "0s") {
			return "<highlight>off<end>";
		}
		return "<highlight>" . $this->util->unixtimeToReadable((int)$this->row->value) . "<end>";
	}

	/** @inheritDoc */
	public function getDescription(): string {
		$msg = "For this setting you must enter a time value or \"off\". ".
			"See <a href='chatcmd:///tell <myname> help budatime'>budatime</a> ".
			"for info on the format of the 'time' parameter.\n\n".
			"To change this setting:\n\n".
			"<highlight>/tell <myname> settings save {$this->row->name} <i>time</i><end>\n\n";
		return $msg;
	}

	/**
	 * @inheritDoc
	 *
	 * @throws \Exception when the time is invalid
	 */
	public function save(string $newValue): string {
		if ($newValue === "0" || strtolower($newValue) === "off") {
			return "0";
		}
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
