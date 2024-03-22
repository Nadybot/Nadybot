<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;

use Nadybot\Core\Attributes\DefineSetting;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ArraySetting extends DefineSetting {
	/**
	 * @inheritDoc
	 *
	 * @param null|int|float|string|bool|mixed[] $defaultValue
	 * @param array<string|int,int|string>       $options      An optional list of values that the setting can be, semi-colon delimited.
	 *                                                         Alternatively, use an associative array [label => value], where label is optional.
	 */
	public function __construct(
		public string $type='text[]',
		public ?string $name=null,
		public null|int|float|string|bool|array $defaultValue=null,
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
	) {
		$this->type = 'array';
	}

	/** @return bool[]|int[]|string[] */
	public function toArray(string $value): array {
		$type = substr($this->type, 0, -2);
		if (!strlen($value)) {
			return [];
		}
		return array_map(
			fn ($item) => $this->typeValue($type, $item),
			explode('|', $value)
		);
	}

	private function typeValue(string $type, string $value): bool|int|string {
		if (in_array($type, ['number', 'time', 'int', 'integer'])) {
			return (int)$value;
		}
		if ($type === 'bool') {
			return (bool)$value;
		}
		return $value;
	}
}
