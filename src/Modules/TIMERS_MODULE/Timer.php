<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use function Safe\json_decode;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Timer extends DBRow {
	/** ID of the timer */
	public int $id;

	/** Name of the timer */
	public string $name;

	/** Name of the person who created that timer */
	public string $owner;

	/** Comma-separated list where to display the alerts (priv,guild,discord) */
	public ?string $mode=null;

	/** Timestamp when this timer goes off */
	public ?int $endtime;

	/** Timestamp when this timer was set */
	public int $settime;

	/** class.method of the function to call on alerts */
	public string $callback;

	/** For repeating timers, this is the repeat interval in seconds */
	public ?string $data=null;

	#[NCA\DB\Ignore]
	public ?string $origin=null;

	/**
	 * A list of alerts, each calling $callback
	 *
	 * @var Alert[]
	 */
	#[NCA\DB\MapRead([self::class, "decodeAlerts"])]
	#[NCA\DB\MapWrite("json_encode")]
	public array $alerts = [];

	/** @return Alert[] */
	public static function decodeAlerts(?string $alerts): array {
		if (!isset($alerts)) {
			return [];
		}
		$alertsData = json_decode($alerts);
		$result = [];
		foreach ($alertsData as $alertData) {
			$alert = new Alert();
			foreach ($alertData as $key => $value) {
				$alert->{$key} = $value;
			}
			$result []= $alert;
		}
		return $result;
	}
}
