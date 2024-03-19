<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncWorldbossDeleteEvent extends SyncEvent {
	public const EVENT_MASK = "sync(worldboss-delete)";

	public string $type = "sync(worldboss-delete)";

	/** For which worldboss: tara, reaper, loren, gauntlet */
	public string $boss;

	/** Name of the person reporting the gauntlet buff */
	public string $sender;
}
