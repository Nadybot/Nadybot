<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(worldboss)')]
class SyncWorldbossEvent extends SyncEvent {
	/**
	 * @param int    $vulnerable UNIX timestamp when the world boss will be vulnerable
	 * @param string $boss       For which worldboss: tara, reaper, loren, gauntlet
	 * @param string $sender     Name of the person reporting this event
	 */
	public function __construct(
		public int $vulnerable,
		public string $boss,
		public string $sender,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
