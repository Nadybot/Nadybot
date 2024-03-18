<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

class GasUpdate extends Event {
	public const EVENT_MASK = "gas-update";

	public function __construct(
		public FeedMessage\GasUpdate $gas
	) {
		$this->type = self::EVENT_MASK;
	}
}
