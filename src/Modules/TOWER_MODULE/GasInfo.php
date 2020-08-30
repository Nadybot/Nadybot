<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

class GasInfo {
	public int $current_time;
	public int $close_time;
	public int $time_until_close_time;
	public int $gas_change;
	public string $gas_level;
	public string $next_state;
	public string $color;
}
