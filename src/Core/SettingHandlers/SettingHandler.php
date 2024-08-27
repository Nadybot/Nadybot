<?php declare(strict_types=1);

namespace Nadybot\Core\SettingHandlers;

use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\{Attributes as NCA, Text, Types\SettingMode};

abstract class SettingHandler {
	#[NCA\Inject]
	protected Text $text;

	/** Construct a new handler out of a given database row */
	public function __construct(
		protected Setting $row
	) {
	}

	public function isEditable(): bool {
		return $this->row->mode === SettingMode::Edit;
	}

	public function getData(): Setting {
		return $this->row;
	}

	public function getModifyLink(): string {
		return Text::makeChatcmd('modify', '/tell <myname> settings change ' . $this->row->name);
	}

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		if (!isset($this->row->intoptions) || $this->row->intoptions === '') {
			return '<highlight>' . htmlspecialchars($this->row->value??'<empty>') . '<end>';
		}
		$options = explode(';', $this->row->options ?? '');
		$intoptions = explode(';', $this->row->intoptions);
		$intoptions2 = array_flip($intoptions);
		if (!isset($this->row->value)) {
			return '<highlight>&lt;empty&gt;<end>';
		}
		$key = $intoptions2[$this->row->value];
		return '<highlight>' . ($options[$key] ?? '&lt;empty&gt;') . '<end>';
	}

	/** Get all options for this setting or null if no options are available */
	public function getOptions(): ?string {
		if (!strlen($this->row->options??'')) {
			return null;
		}
		$options = explode(';', $this->row->options??'');
		if (strlen($this->row->intoptions??'')) {
			$intoptions = explode(';', $this->row->intoptions??'');
			$optionsMap = array_combine($intoptions, $options);
		}
		$msg = "<header2>Predefined Options<end>\n";
		if (isset($optionsMap)) {
			foreach ($optionsMap as $key => $label) {
				$saveLink = Text::makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$key}");
				$msg .= '<tab><highlight>' . htmlspecialchars($label) . "<end> [{$saveLink}]\n";
			}
		} else {
			foreach ($options as $char) {
				$saveLink = Text::makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$char}");
				$msg .= '<tab><highlight>' . htmlspecialchars($char) . "<end> [{$saveLink}]\n";
			}
		}

		return $msg;
	}

	/**
	 * Change this setting
	 *
	 * @throws \Exception if $newValue is not accepted
	 */
	public function save(string $newValue): string {
		return $newValue;
	}

	/** Get a description of the setting */
	abstract public function getDescription(): string;
}
