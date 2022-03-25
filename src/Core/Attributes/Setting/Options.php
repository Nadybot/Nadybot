<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Nadybot\Core\Attributes\DefineSetting;

#[Attribute(Attribute::TARGET_CLASS|Attribute::TARGET_PROPERTY|Attribute::IS_REPEATABLE)]
class Options extends DefineSetting {
	/**
	 * @inheritDoc
	 */
	public function __construct(
		public string $name,
		public string $description,
		public null|int|float|string|bool $defaultValue=null,
		public string $type='options',
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
	) {
		$this->type = 'options';
	}
}
