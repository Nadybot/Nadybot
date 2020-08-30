<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Modules\TIMERS_MODULE\Alert;

class WaveAlert extends Alert {
	/** Which city raid wave are we in? */
	public int $wave = 1;
}
