<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Event;

class CloakRaiseEvent extends Event {
	public const EVENT_MASK = "cloak(raise)";

	/** @param string $player Name of the character raising the cloak */
	public function __construct(
		public string $player,
	) {
		$this->type = self::EVENT_MASK;
	}
}
