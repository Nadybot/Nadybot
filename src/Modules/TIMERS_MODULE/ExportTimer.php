<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\{
	Attributes\Hydrator\CastListToEnums,
	Attributes\Hydrator\Min,
	ExportChannel,
	ExportCharacter
};

class ExportTimer {
	/**
	 * @param int              $endTime        Time when the timer needs to go off
	 * @param ?int             $startTime      Time when the timer was created
	 * @param ?string          $timerName      Name of the timer, used to refer to it
	 * @param ?ExportCharacter $createdBy      The character who created the timer
	 * @param ?ExportChannel[] $channels
	 * @param ?int             $repeatInterval If this is a repeating timer, then set this to the number of seconds between each repeat
	 * @param ?ExportAlert[]   $alerts         Alerts that need to be fired by this alarm. If these are set, then endTime doesn't trigger
	 *                                         an alarm by itself, but requires the endTime alert to be an own alert
	 *
	 * @psalm-param ?list<ExportChannel> $channels
	 * @psalm-param ?list<ExportAlert>   $alerts
	 */
	public function __construct(
		public int $endTime,
		public ?int $startTime=null,
		public ?string $timerName=null,
		public ?ExportCharacter $createdBy=null,
		#[CastListToEnums(ExportChannel::class)] public ?array $channels=null,
		#[Min(1)] public ?int $repeatInterval=null,
		#[CastListToType(ExportAlert::class)] public ?array $alerts=null,
	) {
	}
}
