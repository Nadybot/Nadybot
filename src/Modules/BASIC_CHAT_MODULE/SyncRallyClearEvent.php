<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(rally-clear)')]
class SyncRallyClearEvent extends SyncEvent {
	/** @param string $owner Character who cleared the rally */
	public function __construct(
		public string $owner,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
