<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use function Safe\json_decode;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Timer extends DBRow {
	public int $settime;

	/**
	 * @param string  $name     Name of the timer
	 * @param string  $owner    Name of the person who created that timer
	 * @param ?int    $endtime  Timestamp when this timer goes off
	 * @param int     $settime  Timestamp when this timer was set
	 * @param string  $callback class.method of the function to call on alerts
	 * @param ?string $mode     Comma-separated list where to display the alerts (priv,guild,discord)
	 * @param ?string $data     For repeating timers, this is the repeat interval in seconds
	 * @param ?int    $id       ID of the timer
	 * @param Alert[] $alerts   A list of alerts, each calling $callback
	 */
	public function __construct(
		public string $name,
		public string $owner,
		public ?int $endtime,
		public string $callback="timercontroller.timerCallback",
		public ?string $mode=null,
		public ?string $data=null,
		public ?int $id=null,
		#[NCA\DB\MapRead([self::class, "decodeAlerts"])]
		#[NCA\DB\MapWrite("json_encode")]
		public array $alerts=[],
		#[NCA\DB\Ignore]
		public ?string $origin=null,
		?int $settime=null,
	) {
		$this->settime = $settime ?? time();
	}

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
