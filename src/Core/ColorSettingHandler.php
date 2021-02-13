<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;

/**
 * Class to represent a setting with a color value for BudaBot
 */
class ColorSettingHandler extends SettingHandler {

	/**
	 * Get a displayable representation of the setting
	 */
	public function displayValue(string $sender): string {
		return $this->row->value . htmlspecialchars($this->row->value) . "</font>";
	}

	/**
	 * Describe the valid values for this setting
	 */
	public function getDescription(): string {
		$msg = "For this setting you can set any Color in the HTML Hexadecimal Color Format.\n";
		$msg .= "You can change it manually with the command: \n\n";
		$msg .= "/tell <myname> settings save {$this->row->name} <i>HTML-Color</i>\n\n";
		$msg .= "Or you can choose one of the following Colors\n\n";
		$examples = $this->getExampleColors();
		foreach ($examples as $color => $name) {
			$msg .= "[<a href='chatcmd:///tell <myname> settings save {$this->row->name} {$color}'>Save it</a>] <font color='{$color}'>Example Text</font> ({$name})\n";
		}
		return $msg;
	}

	public static function getExampleColors(): array {
		$examples = [
			"#FF0000" => "Red",
			"#FF6666" => "Light Red",
			"#FFCCCC" => "Rose",
			"#FFFFFF" => "White",
			"#808080" => "Grey",
			"#DDDDDD" => "Light Grey",
			"#9CC6E7" => "Dark Grey",
			"#000000" => "Black",
			"#FFFF00" => "Yellow",
			"#8CB5FF" => "Blue",
			"#00BFFF" => "Deep Sky Blue",
			"#005F6A" => "Petrol",
			"#00DE42" => "Green",
			"#00F700" => "Org Green",
			"#63AD63" => "Pale Green",
			"#FCA712" => "Orange",
			"#FFD700" => "Gold",
			"#FF1493" => "Deep Pink",
			"#EE82EE" => "Violet",
			"#8B7355" => "Brown",
			"#00FFFF" => "Cyan",
			"#000080" => "Navy Blue",
			"#FF8C00" => "Dark Orange",
		];
		return $examples;
	}

	/**
	 * Change this setting
	 *
	 * @throws \Exception when the string is not a valid HTML color
	 */
	public function save(string $newValue): string {
		if (preg_match("/^#([0-9a-f]{6})$/i", $newValue)) {
			return "<font color='$newValue'>";
		} elseif (preg_match("/^<font color='#[0-9a-f]{6}'>$/i", $newValue)) {
			return $newValue;
		}
		throw new Exception("<highlight>{$newValue}<end> is not a valid HTML-Color (example: <i>#FF33DD</i>).");
	}
}
