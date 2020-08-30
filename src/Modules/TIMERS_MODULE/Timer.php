<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

class Timer {
	/** Name of the timer */
	public ?string $name;

	/** Name of the person who created that timer */
	public ?string $owner;

	/** Comma-separated list where to display the alerts (priv,guild,discord) */
	public ?string $mode;

	/** Timestamp when this timer goes off */
	public ?int $endtime;

	/** Timestamp when this timer was set */
	public ?int $settime;

	/** class.method of the function to call on alerts */
	public ?string $callback;

	/** For repeating timers, this is the repeat interval in seconds */
	public ?string $data;

	/**
	 * A list of alerts, each calling $callback
	 * @var Alert[]
	 */
	public array $alerts = [];
}
