<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'tradebot_colors')]
class TradebotColors extends DBTable {
	/** Internal primary key */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $tradebot Name of the tradebnot (Darknet/Lightnet)
	 * @param string         $channel  The channel mask (wtb, *, wt?, ...)
	 * @param string         $color    The 6 hex digits of the color, like FFFFFF
	 * @param ?UuidInterface $id       Internal primary key
	 */
	public function __construct(
		public string $tradebot,
		public string $channel,
		public string $color,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
