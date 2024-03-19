<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\SyncEvent;

class SyncRallyClearEvent extends SyncEvent {
	public const EVENT_MASK = "sync(rally-clear)";

	public string $type = "sync(rally-clear)";

	/** Character who cleared the rally */
	public string $owner;
}
