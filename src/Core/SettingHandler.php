<?php

namespace Budabot\Core;

class SettingHandler {
	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/** @var \Budabot\Core\DBRow $row */
	protected $row;

	/**
	 * Construct a new handler out of a given database row
	 *
	 * @param \Budabot\Core\DBRow $row The database row
	 */
	public function __construct(DBRow $row) {
		$this->row = $row;
	}

	/**
	 * Get a displayable representation of the setting
	 *
	 * @return string
	 */
	public function displayValue() {
		if ($this->row->intoptions != "") {
			$options = explode(";", $this->row->options);
			$intoptions = explode(";", $this->row->intoptions);
			$intoptions2 = array_flip($intoptions);
			$key = $intoptions2[$this->row->value];
			return "<highlight>{$options[$key]}<end>";
		} else {
			return "<highlight>" . htmlspecialchars($this->row->value) . "<end>";
		}
	}

	/**
	 * Get all options for this setting or false if no options are available
	 *
	 * @return string|false false if no options are available
	 */
	public function getOptions() {
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
	 * @param string $newValue The new value
	 * @return string The new value or false if $newValue is invalid
	 * @throws \Exception on certain errors
	 */
	public function save($newValue) {
		return $newValue;
	}

	/**
	 * Get a description of the setting
	 *
	 * @return string
	 */
	public function getDescription() {
		return "No description yet";
	}
}
