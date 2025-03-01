<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\ExportCharacter;

/** An alt character and their validation status */
class AltChar {
	/**
	 * @param ExportCharacter $alt             The alt character.
	 * @param ?bool           $validatedByAlt  Has the alt agreed to be added as an alt?
	 * @param ?bool           $validatedByMain Has the main confirmed that character as their alt?
	 * @param ?int            $time            When was the alt added?
	 */
	public function __construct(
		public ExportCharacter $alt,
		public ?bool $validatedByAlt=null,
		public ?bool $validatedByMain=null,
		public ?int $time=null,
	) {
	}
}
