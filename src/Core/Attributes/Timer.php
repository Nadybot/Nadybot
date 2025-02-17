<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use DateInterval;
use Nadybot\Core\Types\Status;
use Nadybot\Core\Util;
use Safe\DateTimeImmutable;

/** This method should be called periodically at the given interval */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Timer extends HandlesEvent {
	public function __construct(
		string|DateInterval $interval,
		?string $help=null,
		?Status $defaultStatus=null,
	) {
		if ($interval instanceof DateInterval) {
			$interval = Util::unixtimeToReadable(
				(new DateTimeImmutable('@0'))->add($interval)->getTimestamp()
			);
			$interval = str_replace(' ', '', $interval);
		}
		parent::__construct("timer({$interval})", $help, $defaultStatus);
	}
}
