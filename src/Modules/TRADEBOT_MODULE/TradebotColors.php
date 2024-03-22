<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class TradebotColors extends DBRow {
	/**
	 * @param string $tradebot Name of the tradebnot (Darknet/Lightnet)
	 * @param string $channel  The channel mask (wtb, *, wt?, ...)
	 * @param string $color    The 6 hex digits of the color, like FFFFFF
	 * @param ?int   $id       Internal primary key
	 */
	public function __construct(
		public string $tradebot,
		public string $channel,
		public string $color,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
