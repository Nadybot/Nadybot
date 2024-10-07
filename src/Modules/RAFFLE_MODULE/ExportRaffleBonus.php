<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\ExportCharacter;

class ExportRaffleBonus {
	/**
	 * @param ExportCharacter $character   The character with a raffle bonus
	 * @param float           $raffleBonus The bonus (or minus) to apply to the next raffle roll
	 */
	public function __construct(
		public ExportCharacter $character,
		public float $raffleBonus,
	) {
	}
}
