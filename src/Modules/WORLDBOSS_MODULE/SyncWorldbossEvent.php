<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncWorldbossEvent extends SyncEvent {
	public string $type = "sync(worldboss)";

	/** UNIX timestamp when the world boss will be vulnerable */
	public int $vulnerable;

	/** For which worldboss: tara, reaper, loren, gauntlet */
	public string $boss;

	/** Name of the person reporting the gauntlet buff */
	public string $sender;
}
