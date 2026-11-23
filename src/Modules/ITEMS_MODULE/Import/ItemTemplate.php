<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

/** The data of a template that is needed to build item rows */
final class ItemTemplate {
	public function __construct(
		public readonly ?int $ql,
		public readonly string $name,
		public readonly int $icon,
		public readonly int $slot,
		public readonly int $flags,
		public readonly int $properties,
		public readonly int $itemClass,
		public readonly bool $froobFriendly,
	) {
	}
}
