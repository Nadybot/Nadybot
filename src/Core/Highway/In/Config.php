<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

use EventSauce\ObjectHydrator\MapFrom;

/** The configuration of the highway server */
class Config {
	/**
	 * @param int            $connectionsPerIp Allowed connections per IP
	 * @param int            $maxMessageSize   Maximum allowed message size
	 * @param null|int       $maxFrameSize     Maximum frame size of a websocket frame
	 * @param int            $msgPerSec        Maximum allowed messages per second
	 * @param int            $bytesPer10Sec    Maximum allowed bytes per 10 seconds
	 * @param null|RateLimit $msgFreqRatelimit The message frequency rate limit
	 * @param null|RateLimit $msgSizeRatelimit The message size rate limit
	 */
	public function __construct(
		public int $connectionsPerIp,
		public int $maxMessageSize,
		public ?int $maxFrameSize=null,
		public int $msgPerSec=0,
		#[MapFrom('bytes_per_10_sec')] public int $bytesPer10Sec=0,
		public ?RateLimit $msgFreqRatelimit=null,
		public ?RateLimit $msgSizeRatelimit=null,
	) {
	}
}
