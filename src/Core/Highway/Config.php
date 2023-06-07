<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\MapFrom;

class Config {
	public function __construct(
		public int $connectionsPerIp,
		public int $maxMessageSize,
		public int $maxFrameSize,
		public int $msgPerSec=0,
		#[MapFrom("bytes_per_10_sec")] public int $bytesPer10Sec=0,
		public ?RateLimit $msgFreqRatelimit=null,
		public ?RateLimit $msgSizeRatelimit=null,
	) {
	}
}
