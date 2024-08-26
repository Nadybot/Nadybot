<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class RenderedList {
	public function __construct(
		public int $numItems,
		public string $blob,
	) {
	}
}
