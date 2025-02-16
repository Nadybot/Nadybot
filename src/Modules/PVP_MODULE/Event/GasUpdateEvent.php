<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Attributes\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

/** Gas on a tower field changes */
#[Event(mask: 'gas-update')]
class GasUpdateEvent {
	public function __construct(
		public FeedMessage\GasUpdate $gas
	) {
	}
}
