<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

class SettingOption {
	/** Name of this option for displaying */
	public string $name;
	/**
	 * Which value does this option represent?
	 * @var int|string
	 */
	public $value;
}
