<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\SyncEvent;

class SyncRallySetEvent extends SyncEvent {
	public const EVENT_MASK = "sync(rally-set)";

	public string $type = "sync(rally-set)";

	/** Character who created the rally */
	public string $owner;

	/** Name of this rally point */
	public string $name;

	public int $x;
	public int $y;
	public int $pf;
}
