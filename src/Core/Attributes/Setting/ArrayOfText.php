<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ArrayOfText extends ArraySetting {
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
		public ?string $delimiter='|',
	) {
		$this->type = 'text[]';
	}
}
