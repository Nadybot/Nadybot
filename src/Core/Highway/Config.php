<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\MapFrom;

class Config {
	public function __construct(
		public int $connectionsPerIp,
		public int $msgPerSec,
		#[MapFrom("bytes_per_10_sec")] public int $bytesPer10Sec,
		public int $maxMessageSize,
		public int $maxFrameSize,
	) {
	}
}
