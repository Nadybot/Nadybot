<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use Nadybot\Core\DBRow;

class TradebotColors extends DBRow {
	/** Internal primary key */
	public int $id;

	/** Name of the tradebnot (Darknet/Lightnet) */
	public string $tradebot;

	/** The channel mask (wtb, *, wt?, ...) */
	public string $channel;

	/** The 6 hex digits of the color, like FFFFFF */
	public string $color;
}
