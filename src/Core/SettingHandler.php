<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Setting;

abstract class SettingHandler {
	/** @Inject */
	public Text $text;

	protected Setting $row;

	/**
	 * Construct a new handler out of a given database row
	 */
	public function __construct(Setting $row) {
		$this->row = $row;
	}

	public function isEditable(): bool {
		return $this->row->mode === "edit";
	}

	public function getData(): Setting {
		return $this->row;
	}

	/**
	 * Get a displayable representation of the setting
	 */
	public function displayValue(string $sender): string {
		if (!isset($this->row->intoptions) || $this->row->intoptions === "") {
			return "<highlight>" . htmlspecialchars($this->row->value??"<empty>") . "<end>";
		}
		$options = explode(";", $this->row->options ?? "");
		$intoptions = explode(";", $this->row->intoptions);
		$intoptions2 = array_flip($intoptions);
		if (!isset($this->row->value)) {
			return "<highlight>&lt;empty&gt;<end>";
		}
		$key = $intoptions2[$this->row->value];
		return "<highlight>" . ($options[$key] ?? "&lt;empty&gt;") . "<end>";
	}

	/**
	 * Get all options for this setting or false if no options are available
	 */
	public function getOptions(): ?string {
		if (strlen($this->row->options??'')) {
			$options = explode(";", $this->row->options??"");
		}
		if (strlen($this->row->intoptions??'')) {
			$intoptions = explode(";", $this->row->intoptions??"");
			$options_map = array_combine($intoptions, $options??[]);
		}
		if (!empty($options)) {
			$msg = "<header2>Predefined Options<end>\n";
			if (isset($options_map)) {
				foreach ($options_map as $key => $label) {
					$saveLink = $this->text->makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$key}");
					$msg .= "<tab><highlight>" . htmlspecialchars($label) . "<end> [{$saveLink}]\n";
				}
			} else {
				foreach ($options as $char) {
					$saveLink = $this->text->makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$char}");
					$msg .= "<tab><highlight>" . htmlspecialchars($char) . "<end> [{$saveLink}]\n";
				}
			}
		}
		return $msg??"";
	}

	/**
	 * Change this setting
	 *
	 * @throws \Exception if $newValue is not accepted
	 */
	public function save(string $newValue): string {
		return $newValue;
	}

	/**
	 * Get a description of the setting
	 */
	abstract public function getDescription(): string;
}
