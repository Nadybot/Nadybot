<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;

use Nadybot\Core\Attributes\DefineSetting;
use Nadybot\Core\Types\{AccessLevel, SettingMode};

#[Attribute(Attribute::TARGET_PROPERTY)]
class ArraySetting extends DefineSetting {
	/**
	 * @inheritDoc
	 *
	 * @param null|int|float|string|bool|list<mixed> $defaultValue
	 * @param array<string|int,int|string>           $options      An optional list of values that the setting can be, semi-colon delimited.
	 *                                                             Alternatively, use an associative array [label => value], where label is optional.
	 */
	public function __construct(
		string $type='array',
		?string $name=null,
		null|int|float|string|bool|array $defaultValue=null,
		SettingMode $mode=SettingMode::Edit,
		array $options=[],
		AccessLevel $accessLevel=AccessLevel::Mod,
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
		$this->type = 'array';
	}

	/** @return list<bool>|list<int>|list<string> */
	public function toArray(string $value): array {
		$type = substr($this->type, 0, -2);
		if (!strlen($value)) {
			return [];
		}
		return array_map(
			fn (string $item): bool|int|string => $this->typeValue($type, $item),
			explode('|', $value)
		);
	}

	private function typeValue(string $type, string $value): bool|int|string {
		if (in_array($type, ['number', 'time', 'int', 'integer'], true)) {
			return (int)$value;
		}
		if ($type === 'bool') {
			return (bool)$value;
		}
		return $value;
	}
}
