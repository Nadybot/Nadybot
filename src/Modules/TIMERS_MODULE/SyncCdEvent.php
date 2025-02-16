<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(cd)')]
class SyncCdEvent extends SyncEvent {
	/**
	 * @param string $owner   Character who started the countdown
	 * @param string $message Message to display at the end of the countdown
	 */
	public function __construct(
		public string $owner,
		public string $message,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct(
			sourceBot: $sourceBot,
			sourceDimension: $sourceDimension,
			forceSync: $forceSync,
		);
	}
}
