<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Exception;
use Nadybot\Core\Attributes\DefineSetting;
use Nadybot\Core\Types\SettingMode;

#[Attribute(Attribute::TARGET_PROPERTY)]
class TimeOrOff extends DefineSetting {
	/**
	 * @inheritDoc
	 *
	 * @param null|int|float|string|bool|list<mixed> $defaultValue
	 * @param array<string|int,int|string>           $options      An optional list of values that the setting can be, semi-colon delimited.
	 *                                                             Alternatively, use an associative array [label => value], where label is optional.
	 */
	public function __construct(
		string $type='time_or_off',
		?string $name=null,
		null|int|float|string|bool|array $defaultValue=null,
		SettingMode $mode=SettingMode::Edit,
		array $options=[],
		string $accessLevel='mod',
		?string $help=null,
		?bool $confidential=false,
	) {
		parent::__construct(
			type: $type,
			name: $name,
			defaultValue: $defaultValue,
			mode: $mode,
			options: $options,
			accessLevel: $accessLevel,
			help: $help,
			confidential: $confidential,
		);
		$this->type = 'time_or_off';
	}

	public function getValue(): int|string {
		$value = parent::getValue();
		if (!is_string($value) && !is_int($value)) {
			throw new Exception("Type for {$this->name} must be string or int.");
		}
		if (is_int($value)) {
			return "{$value}s";
		}
		return $value;
	}
}
