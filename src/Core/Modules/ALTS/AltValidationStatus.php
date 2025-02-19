<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\StringableTrait;
use Stringable;

/**
 * Dispatched every time a main character validates or rejects one of their alts.
 */
class AltValidationStatus implements Stringable {
	use StringableTrait;

	/**
	 * @param string $added_via         Name of the bot via which the main <-> alt relation was requested
	 * @param bool   $validated_by_main Status if the main character confirmed this to be their alt
	 * @param bool   $validated_by_alt  Status if the alt character confirmed the main to be their main
	 */
	public function __construct(
		public string $added_via,
		public bool $validated_by_main=false,
		public bool $validated_by_alt=false,
	) {
	}
}
