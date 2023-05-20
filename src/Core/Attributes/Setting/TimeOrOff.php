<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Exception;
use Nadybot\Core\Attributes\DefineSetting;

#[Attribute(Attribute::TARGET_PROPERTY)]
class TimeOrOff extends DefineSetting {
	/**
	 * @inheritDoc
	 *
	 * @param array<string|int,int|string> $options An optional list of values that the setting can be, semi-colon delimited.
	 *                                              Alternatively, use an associative array [label => value], where label is optional.
	 */
	public function __construct(
		public string $type='time_or_off',
		public ?string $name=null,
		public null|int|float|string|bool|array $defaultValue=null,
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
	) {
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
