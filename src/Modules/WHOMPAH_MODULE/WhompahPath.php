<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE;

use Nadybot\Core\StringableTrait;
use Stringable;

class WhompahPath implements Stringable {
	use StringableTrait;

	/** @param int[] $connections */
	public function __construct(
		public WhompahCity $current,
		public array $connections=[],
		public bool $visited=false,
		public ?WhompahPath $previous=null,
	) {
	}
}
