<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\ExportCharacter;

/** Information about a player's main and alt characters */
class AltMain {
	/**
	 * @param ExportCharacter $main The main character.
	 * @param AltChar[]       $alts
	 *
	 * @psalm-param list<AltChar> $alts
	 */
	public function __construct(
		public ExportCharacter $main,
		#[CastListToType(AltChar::class)] public array $alts,
	) {
	}
}
