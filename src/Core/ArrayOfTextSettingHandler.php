<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;

/**
 * Class to represent a setting with a text value for NadyBot
 */
#[NCA\SettingHandler("text[]")]
class ArrayOfTextSettingHandler extends SettingHandler {
	#[NCA\Inject]
	public Text $text;

	/** @inheritDoc */
	public function getDescription(): string {
		$msg = "For this setting you can enter any amount of text values you want, separated by a pipe (|)\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>text</i><end>\n\n";
		return $msg;
	}

	/** @inheritDoc */
	public function save(string $newValue): string {
		return $newValue;
	}

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		if (strlen($this->row->value??"") === 0) {
			return "<grey>&lt;empty&gt;<end>";
		}
		$values = array_map(
			fn (string $str): string => htmlspecialchars($str),
			explode("|", $this->row->value ?? "<empty>")
		);
		return $this->text->enumerate(
			...$this->text->arraySprintf("<highlight>%s<end>", ...$values)
		);
	}
}
