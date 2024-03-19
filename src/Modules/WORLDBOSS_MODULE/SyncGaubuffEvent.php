<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncGaubuffEvent extends SyncEvent {
	public const EVENT_MASK = "sync(gaubuff)";

	public string $type = "sync(gaubuff)";

	/** UNIX timestamp when the buff expires */
	public int $expires;

	/** For which faction: neutral, clan or omni */
	public string $faction;

	/** Name of the person reporting the gauntlet buff */
	public string $sender;
}
