<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\Setting;

class ModuleSetting {
	public const TYPE_BOOL = 'bool';
	public const TYPE_TEXT = 'text';
	public const TYPE_NUMBER = 'number';
	public const TYPE_DISCORD_CHANNEL = 'discord_channel';
	public const TYPE_COLOR = 'color';
	public const TYPE_TIME = 'time';
	public const TYPE_OPTIONS = 'options';
	public const TYPE_INT_OPTIONS = 'int_options';

	/** The type of this setting (bool, number, options, etc) */
	public string $type = self::TYPE_TEXT;

	/** The name of the setting */
	public string $name;

	/**
	 * The current value
	 * @var int|string|bool
	 */
	public $value = null;

	/**
	 * A list of predefined options to pick from
	 * @var SettingOption[]
	 */
	public array $options = [];

	/** Is this a fixed setting (like database version) or can it be changed? */
	public bool $editable = true;

	/** A description of what this setting is for */
	public string $description = "Description missing";

	public function __construct(Setting $setting) {
		$this->editable = $setting->mode === 'edit';
		$this->description = $setting->description;
		$this->name = $setting->name;
		if (strlen($setting->options??"")) {
			$options = explode(";", $setting->options);
			$values = $options;
			if (isset($setting->intoptions) && strlen($setting->intoptions)) {
				$values = array_map('intval', explode(";", $setting->intoptions));
			}
			if ($options === ["true", "false"] && $values === [1, 0]) {
				$this->type = static::TYPE_BOOL;
			} else {
				for ($i = 0; $i < count($options); $i++) {
					$option = new SettingOption();
					$option->name = $options[$i];
					$option->value = $values[$i];
					$this->options []= $option;
				}
			}
		}
		$this->description = $setting->description;
		switch ($setting->type) {
			case 'number':
				$this->type = static::TYPE_NUMBER;
				$this->value = (int)$setting->value;
				break;
			case 'color':
				$this->type = static::TYPE_COLOR;
				$this->value = (string)$setting->value;
				break;
			case 'text':
				$this->type = static::TYPE_TEXT;
				$this->value = (string)$setting->value;
				break;
			case 'time':
				$this->type = static::TYPE_TIME;
				$this->value = (int)$setting->value;
				break;
			case 'discord_channel':
				$this->type = static::TYPE_DISCORD_CHANNEL;
				break;
			case 'options':
				if ($this->type === static::TYPE_BOOL) {
					$this->value = (bool)$setting->value;
				} else {
					$this->type = static::TYPE_OPTIONS;
					$this->value = (string)$setting->value;
					if (strlen($setting->intoptions)) {
						$this->type = static::TYPE_INT_OPTIONS;
						$this->value = (int)$setting->value;
					}
				}
				break;
			default:
				$this->type = static::TYPE_TEXT;
				$this->value = (string)$setting->value;
				break;
		}
	}
}
