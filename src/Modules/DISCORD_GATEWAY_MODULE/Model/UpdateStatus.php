<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Modules\DISCORD\{Activity, ReducedStringableTrait};
use Nadybot\Core\{Registry, SettingManager};
use Stringable;

class UpdateStatus implements Stringable {
	use ReducedStringableTrait;

	public const string STATUS_ONLINE = 'online';
	public const string STATUS_DND = 'dnd';
	public const string STATUS_IDLE = 'idle';
	public const string STATUS_INVISIBLE = 'invisible';
	public const string STATUS_OFFLINE = 'offline';

	/**
	 * @param ?int        $since      unix time (in milliseconds) of when the client went idle,
	 *                                or null if the client is not idle
	 * @param ?Activity[] $activities list of activities the client is playing
	 *
	 * @psalm-param ?list<Activity> $activities
	 */
	public function __construct(
		public ?int $since=null,
		#[CastListToType(Activity::class)] public ?array $activities=null,
		public string $status=self::STATUS_ONLINE,
		public bool $afk=false,
	) {
		$sm = Registry::getInstance(SettingManager::class);
		$activityName = $sm->getString('discord_activity_name');
		if (isset($activityName) && strlen($activityName)) {
			$activity = new Activity(name: $activityName);
			$this->activities = [$activity];
		} else {
			$this->activities = [];
		}
	}
}
