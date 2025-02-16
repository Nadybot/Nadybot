<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'cloak(*)')]
abstract class CloakEvent {
	/** @param string $player Name of the character lowering/raising the cloak */
	public function __construct(
		public string $player,
	) {
	}
}
