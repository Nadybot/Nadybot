<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\{Attributes as NCA, StringableTrait};
use Stringable;

#[NCA\Event(mask: 'alt(*)')]
abstract class AltEvent implements Stringable {
	use StringableTrait;

	/**
	 * @param string    $main      Name of the main character
	 * @param string    $alt       Name of the alt
	 * @param null|bool $validated Validated or `null` if unknown
	 */
	public function __construct(
		public string $main,
		public string $alt,
		public ?bool $validated,
	) {
	}
}
