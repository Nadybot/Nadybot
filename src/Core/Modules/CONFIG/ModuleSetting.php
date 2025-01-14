<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\Setting;
use Nadybot\Core\Types\SettingMode;

/** A setting of the bot */
class ModuleSetting {
	public const TYPE_BOOL = 'bool';
	public const TYPE_TEXT = 'text';
	public const TYPE_NUMBER = 'number';
	public const TYPE_DISCORD_CHANNEL = 'discord_channel';
	public const TYPE_COLOR = 'color';
	public const TYPE_TIME = 'time';
	public const TYPE_OPTIONS = 'options';
	public const TYPE_INT_OPTIONS = 'int_options';
	public const TYPE_RANK = 'rank';

	/**
	 * @param string                                      $name        The name of the setting
	 * @param null|int|string|bool|list<int>|list<string> $value       The current value
	 * @param list<SettingOption>                         $options     A list of predefined options to pick from
	 * @param bool                                        $editable    Is this a fixed setting (like database version) or can it be changed?
	 * @param string                                      $description A description of what this setting is for
	 */
	public function __construct(
		public string $name,
		public string $type=self::TYPE_TEXT,
		public null|int|string|bool|array $value=null,
		public array $options=[],
		public bool $editable=true,
		public string $description='Description missing',
		public ?string $help=null,
	) {
	}

	public static function fromSetting(Setting $setting): self {
		$result = new self(
			editable: $setting->mode === SettingMode::Edit,
			description: $setting->description??'No description given',
			name: $setting->name
		);
		if (strlen($setting->options??'')) {
			$options = explode(';', $setting->options??'');
			$values = $options;
			if (isset($setting->intoptions) && strlen($setting->intoptions)) {
				if (in_array($setting->type, ['number', 'time'], true)) {
					$values = array_map('intval', explode(';', $setting->intoptions));
				} else {
					$values = explode(';', $setting->intoptions);
				}
			}
			if ($options === ['true', 'false'] && $values === [1, 0]) {
				$result->type = static::TYPE_BOOL;
			} else {
				for ($i = 0; $i < count($options); $i++) {
					$result->options []= new SettingOption(
						name: $options[$i],
						value: $values[$i],
					);
				}
			}
		}
		$result->description = $setting->description??'No description given';
		switch ($setting->type) {
			case 'number':
				$result->type = static::TYPE_NUMBER;
				$result->value = (int)$setting->value;
				break;
			case 'color':
				$result->type = static::TYPE_COLOR;
				$result->value = (string)$setting->value;
				break;
			case 'text':
				$result->type = static::TYPE_TEXT;
				$result->value = (string)$setting->value;
				break;
			case 'time':
				$result->type = static::TYPE_TIME;
				$result->value = (int)$setting->value;
				break;
			case 'discord_channel':
				$result->type = static::TYPE_DISCORD_CHANNEL;
				$result->value = (string)$setting->value;
				break;
			case 'bool':
				$result->type = static::TYPE_BOOL;
				$result->value = (bool)$setting->value;
				break;
			case 'options':
				if ($result->type === static::TYPE_BOOL) {
					$result->value = (bool)$setting->value;
				} else {
					$result->type = static::TYPE_OPTIONS;
					$result->value = (string)$setting->value;
					if (strlen($setting->intoptions??'')) {
						$result->type = static::TYPE_INT_OPTIONS;
						$result->value = (int)$setting->value;
					}
				}
				break;
			case 'rank':
				$result->type = static::TYPE_RANK;
				$result->value = (string)$setting->value;
				break;
			default:
				$result->type = static::TYPE_TEXT;
				$result->value = (string)$setting->value;
				break;
		}
		return $result;
	}
}
