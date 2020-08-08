<?php declare(strict_types=1);

namespace Nadybot\Core;

abstract class SettingHandler {
	/** @Inject */
	public Text $text;

	protected DBRow $row;

	/**
	 * Construct a new handler out of a given database row
	 */
	public function __construct(DBRow $row) {
		$this->row = $row;
	}

	/**
	 * Get a displayable representation of the setting
	 */
	public function displayValue(): string {
		if ($this->row->intoptions === "") {
			return "<highlight>" . htmlspecialchars($this->row->value) . "<end>";
		}
		$options = explode(";", $this->row->options ?? "");
		$intoptions = explode(";", $this->row->intoptions ?? "");
		$intoptions2 = array_flip($intoptions);
		$key = $intoptions2[$this->row->value];
		return "<highlight>{$options[$key]}<end>";
	}

	/**
	 * Get all options for this setting or false if no options are available
	 */
	public function getOptions(): ?string {
		if ($this->row->options != '') {
			$options = explode(";", $this->row->options);
		}
		if ($this->row->intoptions != '') {
			$intoptions = explode(";", $this->row->intoptions);
			$options_map = array_combine($intoptions, $options);
		}
		if ($options) {
			$msg = "Predefined Options:\n";
			if ($intoptions) {
				foreach ($options_map as $key => $label) {
					$save_link = $this->text->makeChatcmd('Select', "/tell <myname> settings save {$this->row->name} {$key}");
					$msg .= "<tab> <highlight>{$label}<end> ({$save_link})\n";
				}
			} else {
				foreach ($options as $char) {
					$save_link = $this->text->makeChatcmd('Select', "/tell <myname> settings save {$this->row->name} {$char}");
					$msg .= "<tab> <highlight>{$char}<end> ({$save_link})\n";
				}
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

	/**
	 * Get a description of the setting
	 */
	abstract public function getDescription(): string;
}
