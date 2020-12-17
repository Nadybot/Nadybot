<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

class AltValidationStatus {
	/** Status if the main character confirmed this to be their alt */
	public bool $validated_by_main = false;

	/** Status if the alt character confirmed the main to be their main */
	public bool $validated_by_alt = false;

	/** Name of the bot via which the main <-> alt relation was requested */
	public string $added_via;
}
