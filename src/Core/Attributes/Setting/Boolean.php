<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Nadybot\Core\Attributes\DefineSetting;

#[Attribute(Attribute::TARGET_CLASS|Attribute::IS_REPEATABLE)]
class Boolean extends DefineSetting {
	/**
	 * @inheritDoc
	 */
	public function __construct(
		public string $name,
		public string $description,
		public int|float|string|bool $defaultValue,
		public string $type='bool',
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
	) {
		$this->type = 'bool';
	}
}
