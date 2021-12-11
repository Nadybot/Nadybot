<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncCdEvent extends SyncEvent {
	public string $type = "sync(cd)";

	/** Character who started the countdown */
	public string $owner;

	/** Message to display at the end of the countdown */
	public string $message;
}
