<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use Nadybot\Core\{Attributes as NCA, Text};

/**
 * Class to represent a setting with a text value for NadyBot
 */
#[NCA\SettingHandler('text[]')]
class ArrayOfTextSettingHandler extends SettingHandler {
	/** @inheritDoc */
	public function getDescription(): string {
		$msg = "For this setting you can enter any amount of text values you want, separated by a pipe (|)\n";
		$msg .= "To change this setting:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} <i>text</i><end>\n\n";
		$msg .= "To set an empty value:\n\n";
		$msg .= "<highlight>/tell <myname> settings save {$this->row->name} ---<end> [".
			Text::makeChatcmd('clear', "/tell <myname> settings save {$this->row->name} ---").
			"]\n\n";
		return $msg;
	}

	/** @inheritDoc */
	public function save(string $newValue): string {
		if ($newValue === '---') {
			$newValue = '';
		}
		return $newValue;
	}

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		if (strlen($this->row->value??'') === 0) {
			return '<grey>&lt;empty&gt;<end>';
		}
		$values = array_map(
			static fn (string $str): string => htmlspecialchars($str),
			explode('|', $this->row->value ?? '<empty>')
		);
		return Text::enumerate(
			...Text::arraySprintf('<highlight>%s<end>', ...$values)
		);
	}
}
