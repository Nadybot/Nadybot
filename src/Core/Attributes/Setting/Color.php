<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Nadybot\Core\Attributes\DefineSetting;

#[Attribute(Attribute::TARGET_CLASS|Attribute::TARGET_PROPERTY|Attribute::IS_REPEATABLE)]
class Color extends DefineSetting {
	/**
	 * @inheritDoc
	 */
	public function __construct(
		public string $name,
		public string $description,
		public null|int|float|string|bool $defaultValue=null,
		public string $type='color',
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
	) {
		$this->type = 'color';
		if (preg_match("/^#?([0-9a-f]{6})$/i", (string)$defaultValue, $matches)) {
			$this->defaultValue = "<font color='#{$matches[1]}'>";
		}
	}
}
